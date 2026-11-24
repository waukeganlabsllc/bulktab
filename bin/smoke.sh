#!/usr/bin/env bash
# Deterministic end-to-end smoke test on a throwaway WordPress + WooCommerce
# store (WordPress Playground). Prints PASS/FAIL per requirement.
#
#   bin/smoke.sh [path-to-bulktab-pro]
#
# Needs: node 20+, php (host), curl. Installs @wp-playground/cli on first run.
set -euo pipefail

FREE="$(cd "$(dirname "$0")/.." && pwd)"
PRO="${1:-}"
OUT="${SMOKE_OUT:-$(mktemp -d)}"
PORT="${SMOKE_PORT:-9400}"
TOOLS="${SMOKE_TOOLS:-$HOME/.cache/bulktab-smoke}"

mkdir -p "$OUT" "$TOOLS"
rm -f "$OUT"/*.json "$OUT"/debug.log "$OUT"/jar.txt
if [ ! -x "$TOOLS/node_modules/.bin/wp-playground-cli" ]; then
	(cd "$TOOLS" && npm init -y >/dev/null && npm install --no-fund --no-audit @wp-playground/cli >/dev/null)
fi

BLUEPRINT="$FREE/tests/smoke/blueprint.json"
MOUNTS=(--mount="$FREE:/wordpress/wp-content/plugins/bulktab" --mount="$OUT:/smoke-out")
if [ -n "$PRO" ]; then
	MOUNTS+=(--mount="$PRO:/wordpress/wp-content/plugins/bulktab-pro")
	BLUEPRINT="$PRO/tests/smoke/blueprint.json"
	MOUNTS+=(--define-bool BULKTAB_PRO_DEV true --define WP_ENVIRONMENT_TYPE local)
fi

"$TOOLS/node_modules/.bin/wp-playground-cli" server --port="$PORT" --php=8.3 "${MOUNTS[@]}" \
	--blueprint="$BLUEPRINT" --blueprint-may-read-adjacent-files \
	--define-bool WP_DEBUG true --define-bool WP_DEBUG_DISPLAY false --define WP_DEBUG_LOG /smoke-out/debug.log \
	> "$OUT/server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT

for _ in $(seq 1 120); do
	grep -q "Ready!" "$OUT/server.log" 2>/dev/null && break
	if ! kill -0 $SERVER 2>/dev/null; then cat "$OUT/server.log"; exit 2; fi
	sleep 3
done

php "$FREE/tests/smoke/check.php" "$OUT" "http://127.0.0.1:$PORT" "$PRO"
