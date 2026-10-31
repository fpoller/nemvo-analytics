#!/usr/bin/env bash
# Prepares the development site: WP-CLI, WordPress, the plugin, demo pages,
# the dev services (Valkey, Cap), the free IP databases and 120 days of demo
# traffic. Safe to run again.
#
# Runs automatically when the dev container is created. Without VS Code:
#   docker compose -f .devcontainer/docker-compose.yml up -d
#   docker compose -f .devcontainer/docker-compose.yml exec wordpress bash /workspace/.devcontainer/setup.sh
set -euo pipefail

WP_ROOT=/var/www/html
SITE_URL="http://localhost:${WP_PORT:-8090}"

if [ ! -x /usr/local/bin/wp ]; then
	echo "Installing WP-CLI..."
	curl -fsSL -o /usr/local/bin/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
	# Run WP-CLI as www-data so the files it creates stay writable for Apache.
	printf '#!/bin/sh\nexec runuser -u www-data -- php /usr/local/bin/wp-cli.phar --path=%s "$@"\n' "$WP_ROOT" >/usr/local/bin/wp
	chmod +x /usr/local/bin/wp
fi

echo "Waiting for the WordPress files..."
for _ in $(seq 1 60); do
	[ -f "$WP_ROOT/wp-config.php" ] && [ -f "$WP_ROOT/wp-includes/version.php" ] && break
	sleep 1
done

if ! wp core is-installed 2>/dev/null; then
	wp core install --url="$SITE_URL" --title="Nemvo" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
fi

wp option update timezone_string Europe/Stockholm
wp option update start_of_week 1
wp rewrite structure '/blog/%postname%/'
wp plugin activate nemvo-analytics
wp eval-file /workspace/.devcontainer/demo-content.php
wp eval-file /workspace/.devcontainer/configure-services.php
wp rewrite flush

# Free DB-IP Lite databases (CC BY 4.0) for countries and networks.
for edition in country asn; do
	[ -f "$WP_ROOT/wp-content/uploads/nemvo-analytics/dbip-$edition-lite.mmdb" ] || wp nemvo-analytics geoip-update --edition=$edition || true
done

wp nemvo-analytics seed --days=120 --if-empty
wp nemvo-analytics status

cat <<EOF

Ready.
  Site       $SITE_URL
  Dashboard  $SITE_URL/wp-admin/admin.php?page=nemvo-analytics   (admin / admin)
  Settings   $SITE_URL/wp-admin/admin.php?page=nemvo-analytics-settings
  Mailpit    http://localhost:${MAILPIT_PORT:-8095}
  Adminer    http://localhost:${ADMINER_PORT:-8091}
  Cap        http://localhost:${CAP_PORT:-8092}

Administrators are not tracked. Browse the site in a private window to see your own visits in Realtime.
EOF
