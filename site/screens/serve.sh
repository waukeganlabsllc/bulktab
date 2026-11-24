#!/usr/bin/env bash
# Boot the "Harbor Outfitters" screenshot store in WordPress Playground.
#   screens/serve.sh free|pro PORT OUT_DIR
# Needs @wp-playground/cli in $TOOLS (default /home/claude/tools).
set -euo pipefail
MODE="${1:-free}"
PORT="${2:-9500}"
OUT="${3:-$(mktemp -d)}"
HERE="$(cd "$(dirname "$0")" && pwd)"
FREE="$(cd "$HERE/../.." && pwd)"
PRO="${BULKTAB_PRO:-$FREE/../bulktab-pro}"
TOOLS="${TOOLS:-/home/claude/tools}"
mkdir -p "$OUT"
ARGS=(--mount="$FREE:/wordpress/wp-content/plugins/bulktab" --mount="$HERE:/screens" --mount="$OUT:/screens-out")
BLUEPRINT="$HERE/blueprint.json"
if [ "$MODE" = "pro" ]; then
	ARGS+=(--mount="$(cd "$PRO" && pwd):/wordpress/wp-content/plugins/bulktab-pro" --define-bool BULKTAB_PRO_DEV true --define WP_ENVIRONMENT_TYPE local)
	BLUEPRINT="$HERE/blueprint-pro.json"
fi
exec "$TOOLS/node_modules/.bin/wp-playground-cli" server --port="$PORT" --php=8.3 "${ARGS[@]}" \
	--blueprint="$BLUEPRINT" --blueprint-may-read-adjacent-files
