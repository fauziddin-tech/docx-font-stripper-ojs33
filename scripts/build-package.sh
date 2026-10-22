#!/usr/bin/env bash
# Build docxFontStripper-<version>.tar.gz for OJS: Settings > Website > Plugins > Upload a New Plugin.
# The archive contains a single top-level directory "docxFontStripper/", which is
# what lib/pkp/classes/plugins/PluginHelper.inc.php (extractPlugin) expects.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="docxFontStripper"
VERSION="$(sed -n 's:.*<release>\(.*\)</release>.*:\1:p' "$ROOT/version.xml")"
[ -n "$VERSION" ] || { echo "No <release> in version.xml" >&2; exit 1; }

OUT_DIR="${1:-$ROOT/dist}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/$NAME" "$OUT_DIR"
cp "$ROOT/index.php" "$ROOT/version.xml" "$ROOT/DocxFontStripperPlugin.inc.php" "$ROOT/README.md" "$STAGE/$NAME/"
cp -R "$ROOT/classes" "$ROOT/locale" "$STAGE/$NAME/"

for f in "$STAGE/$NAME"/*.php "$STAGE/$NAME"/classes/*.php; do
	php -l "$f" >/dev/null
done

ARCHIVE="$OUT_DIR/$NAME-$VERSION.tar.gz"
tar -C "$STAGE" --owner=0 --group=0 --numeric-owner -czf "$ARCHIVE" "$NAME"
echo "$ARCHIVE"
