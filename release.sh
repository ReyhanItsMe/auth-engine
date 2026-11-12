#!/usr/bin/env bash
# Builds the Google Drive release assets (source-code-full/, auth-engine-full.zip,
# patches/patch-v<version>.zip, docs/, CHANGELOG.md).
#
#   ./release.sh                     # → ../drive-release/auth-engine
#   ./release.sh --out=/some/folder
#   ./release.sh --help
set -euo pipefail
cd "$(dirname "$0")"
exec php scripts/release.php "$@"
