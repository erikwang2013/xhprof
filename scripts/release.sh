#!/usr/bin/env bash
#
# Local release pre-flight for xhprof.
#
#   scripts/release.sh            # validate, build the PEAR tarball, verify it
#   scripts/release.sh --clean    # same, then delete the tarball when done
#
# Stops before anything irreversible: tagging, pushing and the PECL upload are
# printed as a checklist and left to a human.
set -euo pipefail

cd "$(dirname "$0")/.."

CLEAN=0
[ "${1:-}" = "--clean" ] && CLEAN=1

command -v pear >/dev/null 2>&1 || { echo "error: pear is not installed" >&2; exit 1; }

VERSION=$(sed -n 's:.*<release>\(.*\)</release>.*:\1:p' package.xml | head -1)
[ -n "$VERSION" ] || { echo "error: could not read <release> from package.xml" >&2; exit 1; }
TARBALL="xhprof-${VERSION}.tgz"
echo "==> package.xml release version: $VERSION"

echo "==> pear package-validate"
pear package-validate >/dev/null

echo "==> pear package"
pear package >/dev/null

echo "==> verifying $TARBALL"
[ -s "$TARBALL" ] || { echo "error: $TARBALL missing or empty" >&2; exit 1; }
# tar entries live under the version directory, package.xml sits at the root
LISTING=$(tar -tzf "$TARBALL" | sed "s:^xhprof-${VERSION}/::")
for f in package.xml extension/xhprof.c extension/php_xhprof.h \
         xhprof_lib/utils/xhprof_lib.php xhprof_html/index.php travis/run-test.sh; do
  echo "$LISTING" | grep -qx "$f" || { echo "error: $f missing from $TARBALL" >&2; exit 1; }
done
SHA=$(sha256sum "$TARBALL" 2>/dev/null || shasum -a 256 "$TARBALL") # macOS has no sha256sum
echo "ok: $TARBALL ($(du -h "$TARBALL" | cut -f1)), sha256 ${SHA%% *}"

cat <<EOF

Release checklist (run by hand):

  1. Update <date>, <time>, <version><release> and <notes> in package.xml, plus the CHANGELOG file.
  2. git commit -m "v$VERSION: ..."   # then: git tag -a v$VERSION -m "v$VERSION" && git push --follow-tags
  3. Draft the GitHub release for tag v$VERSION, attach $TARBALL.

PECL upload (manual, after the tag is pushed):

  4. Log in at https://pecl.php.net/ (account with xhprof Karma).
  5. On https://pecl.php.net/package/xhprof -> "Upload Release": select $TARBALL.
     PECL derives the version from package.xml; do not rename the file.
  6. Verify the new release shows on the package page and that
     "pecl install xhprof" picks it up, then announce it in the GitHub release.
EOF

if [ "$CLEAN" = 1 ]; then
  rm -f "$TARBALL"
  echo "==> removed $TARBALL"
fi
