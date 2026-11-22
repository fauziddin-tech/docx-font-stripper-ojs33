#!/usr/bin/env bash
# Build the plugin archive for OJS: Settings > Website > Plugins > Upload a New Plugin.
#
#   scripts/build-package.sh [OUT_DIR] [ojs33|ojs35]
#
#   ojs33 (default): docxFontStripper-<version>.tar.gz        OJS 3.3
#   ojs35:           docxFontStripper-ojs3.5-<version>.tar.gz OJS 3.5
#
# Both archives contain a single top-level directory "docxFontStripper/",
# which is what OJS's PluginHelper expects. The OJS 3.5 build takes the
# shared classes/ and js/ and adds the files in ojs35/ (plugin class,
# version.xml, locale/en and locale/id, classes/FileSizeLookup.php).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="docxFontStripper"
OUT_DIR="${1:-$ROOT/dist}"
TARGET="${2:-ojs33}"

version_of() { sed -n 's:.*<release>\(.*\)</release>.*:\1:p' "$1"; }
VERSION="$(version_of "$ROOT/version.xml")"
[ -n "$VERSION" ] || { echo "No <release> in version.xml" >&2; exit 1; }
if [ "$(version_of "$ROOT/ojs35/version.xml")" != "$VERSION" ]; then
	echo "ojs35/version.xml release differs from version.xml ($VERSION)" >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$NAME" "$OUT_DIR"

case "$TARGET" in
	ojs33)
		cp "$ROOT/index.php" "$ROOT/version.xml" "$ROOT/DocxFontStripperPlugin.inc.php" "$ROOT/README.md" "$STAGE/$NAME/"
		cp -R "$ROOT/classes" "$ROOT/js" "$ROOT/locale" "$STAGE/$NAME/"
		ARCHIVE="$OUT_DIR/$NAME-$VERSION.tar.gz"
		;;
	ojs35)
		cp "$ROOT/ojs35/version.xml" "$ROOT/ojs35/DocxFontStripperPlugin.php" "$ROOT/README.md" "$STAGE/$NAME/"
		cp -R "$ROOT/classes" "$ROOT/js" "$ROOT/ojs35/locale" "$STAGE/$NAME/"
		cp "$ROOT"/ojs35/classes/*.php "$STAGE/$NAME/classes/"
		ARCHIVE="$OUT_DIR/$NAME-ojs3.5-$VERSION.tar.gz"
		;;
	*)
		echo "Unknown target $TARGET (ojs33 or ojs35)" >&2
		exit 1
		;;
esac

for f in "$STAGE/$NAME"/*.php "$STAGE/$NAME"/classes/*.php; do
	php -l "$f" >/dev/null
done

tar -C "$STAGE" --owner=0 --group=0 --numeric-owner -czf "$ARCHIVE" "$NAME"
echo "$ARCHIVE"
