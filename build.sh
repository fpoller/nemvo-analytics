#!/usr/bin/env bash
# Builds an installable plugin zip: dist/nemvo-analytics-<version>.zip
set -euo pipefail
cd "$(dirname "$0")"

plugin=nemvo-analytics
version=$(sed -n 's/^ \* Version: *//p' $plugin/$plugin.php)
constant=$(sed -n "s/.*NEMVO_ANALYTICS_VERSION', '\([^']*\)'.*/\1/p" $plugin/$plugin.php)
stable=$(sed -n 's/^Stable tag: *//p' $plugin/readme.txt)
if [ "$version" != "$constant" ] || [ "$version" != "$stable" ]; then
	echo "Version mismatch: header $version, constant $constant, readme.txt Stable tag $stable" >&2
	exit 1
fi
if ! grep -q "^= $version =" $plugin/readme.txt; then
	echo "readme.txt has no changelog entry for $version" >&2
	exit 1
fi

# The tracker is printed inline from its minified copy, which must match the source.
if command -v npx >/dev/null 2>&1; then
	tools/minify-tracker.sh >/dev/null
elif [ $plugin/assets/js/tracker.js -nt $plugin/assets/js/tracker.min.js ]; then
	echo "assets/js/tracker.min.js is older than tracker.js; run tools/minify-tracker.sh" >&2
	exit 1
fi

mkdir -p dist
zip_file="dist/$plugin-$version.zip"
rm -f "$zip_file"
zip -qr "$zip_file" $plugin -x '*.DS_Store' -x '*/.git*' -x '*/node_modules/*'
echo "$zip_file"
