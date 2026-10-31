#!/usr/bin/env bash
# Installs a built zip on a brand-new WordPress (nothing configured: no IP databases, no external
# database, no queue, no Cap) and checks that it works out of the box: wp-admin screens and the
# frontend load without PHP errors, a hit sent like a browser does ends up in the statistics, the
# reports answer, uninstall cleans up, and Plugin Check reports no errors. Also lints on PHP 7.4.
# Usage: ./smoke-test.sh dist/nemvo-analytics-x.y.z.zip
set -euo pipefail
cd "$(dirname "$0")"

zip_file=${1:?usage: $0 dist/nemvo-analytics-x.y.z.zip}
project=nemvo-analytics-smoke
port=${SMOKE_PORT:-8097}
url="http://localhost:$port"
work=$(mktemp -d)
trap 'docker compose -p $project -f "$work/compose.yml" down -v >/dev/null 2>&1; rm -rf "$work"' EXIT

fail=0
check() { # name, url, [cookie jar]
	local body status
	body=$(curl -s -w '\n%{http_code}' ${3:+-b "$3"} "$2")
	status=${body##*$'\n'}
	if [ "$status" != 200 ] || grep -qiE '(fatal error|warning|notice|deprecated)</b>:|critical error' <<<"$body"; then
		echo "FAIL $1 ($status)"; fail=1
	else
		echo "ok   $1"
	fi
}
expect() { # name, condition result ("1" = pass)
	if [ "$2" = 1 ]; then echo "ok   $1"; else echo "FAIL $1"; fail=1; fi
}

echo "== PHP 7.4 lint"
lint=$(docker run --rm -v "$PWD/nemvo-analytics":/src -w /src php:7.4-cli sh -c 'find . -name "*.php" -exec php -l {} \; | grep -v "^No syntax errors" || true')
if [ -n "$lint" ]; then echo "$lint"; echo "FAIL php 7.4 lint"; fail=1; else echo "ok   php 7.4 lint"; fi

cp "$zip_file" "$work/plugin.zip"
cat >"$work/compose.yml" <<YAML
services:
  db:
    image: mariadb:11.4
    environment: { MARIADB_DATABASE: wp, MARIADB_USER: wp, MARIADB_PASSWORD: wp, MARIADB_ROOT_PASSWORD: root }
    healthcheck: { test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"], interval: 3s, retries: 30 }
  wordpress:
    image: wordpress:php8.3-apache
    depends_on: { db: { condition: service_healthy } }
    ports: ["$port:80"]
    environment: { WORDPRESS_DB_HOST: db, WORDPRESS_DB_USER: wp, WORDPRESS_DB_PASSWORD: wp, WORDPRESS_DB_NAME: wp, WORDPRESS_DEBUG: "1" }
    volumes: ["$work/plugin.zip:/tmp/plugin.zip:ro"]
YAML

echo "== Starting a clean WordPress"
docker compose -p $project -f "$work/compose.yml" up -d >/dev/null 2>&1
dc() { docker compose -p $project -f "$work/compose.yml" exec -T wordpress "$@"; }
dc bash -c 'curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp'
wp() { dc runuser -u www-data -- wp --path=/var/www/html "$@"; }
for _ in $(seq 1 60); do wp core is-installed >/dev/null 2>&1 && break; wp db check >/dev/null 2>&1 && break; sleep 1; done
wp core install --url="$url" --title=Smoke --admin_user=admin --admin_password=admin --admin_email=a@example.com --skip-email >/dev/null
wp rewrite structure '/%postname%/' >/dev/null

echo "== Installing $zip_file"
wp plugin install /tmp/plugin.zip --activate
wp post create --post_type=page --post_status=publish --post_title=Smoke --post_name=smoke >/dev/null

jar="$work/cookies"
curl -s -c "$jar" -b "$jar" -o /dev/null -d "log=admin&pwd=admin&testcookie=1" -b "wordpress_test_cookie=WP%20Cookie%20check" "$url/wp-login.php"
check "frontend page" "$url/smoke/"
expect "tracker printed on the page" "$(curl -s "$url/smoke/" | grep -c 'id="nva-tracker"' | sed 's/^[1-9][0-9]*$/1/')"
for page in nemvo-analytics nemvo-analytics-realtime nemvo-analytics-settings; do
	check "admin $page" "$url/wp-admin/admin.php?page=$page" "$jar"
done
check "admin dashboard (widget)" "$url/wp-admin/index.php" "$jar"
check "admin pages list (views column)" "$url/wp-admin/edit.php?post_type=page" "$jar"
check "site health" "$url/wp-admin/site-health.php" "$jar"

echo "== A visit, sent like the tracker sends it"
ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'
pv=0123456789abcdef
status=$(curl -s -o /dev/null -w '%{http_code}' -A "$ua" -H 'Accept-Language: sv-SE' -H 'Sec-Fetch-Site: same-origin' -H 'Content-Type: text/plain' \
	--data "{\"t\":\"pv\",\"id\":\"$pv\",\"u\":\"$url/smoke/?utm_source=smoke\",\"r\":\"https://www.google.com/\",\"ti\":\"Smoke\",\"w\":1440,\"tz\":\"Europe/Stockholm\",\"l\":\"sv-SE\",\"b\":0,\"c\":{\"type\":\"page\",\"id\":0}}" "$url/wp-json/nemvo/v1/e")
expect "pageview accepted ($status)" "$([ "$status" = 200 ] && echo 1)"
curl -s -o /dev/null -A "$ua" -H 'Content-Type: text/plain' --data "{\"t\":\"e\",\"id\":\"$pv\",\"d\":15000,\"s\":80,\"i\":1}" "$url/wp-json/nemvo/v1/e"
row=$(wp eval '$db = NemvoAnalytics\DB::conn(); echo $db->get_var( "SELECT CONCAT(country, \"|\", channel, \"|\", duration, \"|\", bot_score) FROM " . NemvoAnalytics\DB::table( "sessions" ) . " WHERE utm_source = \"smoke\"" );')
expect "visit stored in the WordPress database: $row" "$([ "$row" = 'SE|Organic Search|15|0' ] && echo 1)"
nonce=$(curl -s -b "$jar" "$url/wp-admin/admin-ajax.php?action=rest-nonce")
today=$(wp eval 'echo current_datetime()->format("Y-m-d");')
batch=$(curl -s -b "$jar" -H "X-WP-Nonce: $nonce" -H 'Content-Type: application/json' \
	--data "{\"requests\":[{\"route\":\"overview\",\"params\":{\"from\":\"$today\",\"to\":\"$today\"}},{\"route\":\"breakdown\",\"params\":{\"dim\":\"source\"}},{\"route\":\"sessions\",\"params\":{}},{\"route\":\"bots\",\"params\":{}},{\"route\":\"realtime\",\"params\":{}}]}" \
	"$url/wp-json/nemvo/v1/stats/batch")
expect "reports answer: $(cut -c1-80 <<<"$batch")" "$(grep -c '"visits":1' <<<"$batch" | sed 's/^[1-9][0-9]*$/1/')"
bots=$(wp eval '$db = NemvoAnalytics\DB::conn(); echo $db->get_var( "SELECT COUNT(*) FROM " . NemvoAnalytics\DB::table( "sessions" ) . " WHERE browser = \"curl\" AND bot_score >= 50" );')
expect "page requests without JavaScript are recorded on the server as automated ($bots)" "$([ "${bots:-0}" -ge 1 ] && echo 1)"

echo "== Plugin Check (errors only)"
wp plugin install plugin-check --activate >/dev/null 2>&1
pcp=$(wp plugin check nemvo-analytics --ignore-warnings --format=csv 2>&1 || true)
if grep -q 'No errors found' <<<"$pcp"; then
	echo "ok   plugin check"
else
	echo "$pcp"; echo "FAIL plugin check"; fail=1
fi

echo "== Uninstall"
wp eval 'update_option( "nemvo_analytics_settings", array_merge( get_option( "nemvo_analytics_settings", array() ), array( "drop_on_uninstall" => 1 ) ) );'
wp plugin deactivate nemvo-analytics >/dev/null && wp plugin uninstall nemvo-analytics >/dev/null
left=$(wp eval 'global $wpdb; echo count( $wpdb->get_col( "SHOW TABLES LIKE \"{$wpdb->prefix}nemvo_%\"" ) ) + count( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \"nemvo_analytics%\"" ) );')
expect "uninstall removes tables and options ($left left)" "$([ "$left" = 0 ] && echo 1)"

[ $fail = 0 ] && echo "SMOKE TEST PASSED" || { echo "SMOKE TEST FAILED"; exit 1; }
