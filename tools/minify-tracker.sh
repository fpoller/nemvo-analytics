#!/usr/bin/env bash
# Regenerates nemvo-analytics/assets/js/tracker.min.js, the copy printed inline on every page,
# from tracker.js (which stays in the plugin as the readable source).
set -euo pipefail
cd "$(dirname "$0")/.."
npx --yes terser@5.51.2 nemvo-analytics/assets/js/tracker.js \
	--compress --mangle --comments '/^!/' \
	-o nemvo-analytics/assets/js/tracker.min.js
wc -c nemvo-analytics/assets/js/tracker.js nemvo-analytics/assets/js/tracker.min.js
