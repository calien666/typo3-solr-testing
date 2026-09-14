#!/usr/bin/env bash

#
# Finds files with a UTF-8 byte order mark. TYPO3 forbids them: a BOM before
# "<?php" is emitted as output and breaks header handling at runtime.
#

set -o pipefail

THIS_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null && pwd)"
cd "${THIS_SCRIPT_DIR}/../../" || exit 1

FILES_WITH_BOM=$(
    find Classes Resources Tests Build -type f \
        \( -name '*.php' -o -name '*.xml' -o -name '*.yaml' -o -name '*.yml' -o -name '*.csv' -o -name '*.neon' \) \
        -print0 2>/dev/null \
    | xargs -0 -r grep -l $'\xef\xbb\xbf'
)

if [ -n "${FILES_WITH_BOM}" ]; then
    echo "Found UTF-8 byte order mark in:" >&2
    echo "${FILES_WITH_BOM}" >&2
    exit 1
fi

echo "No UTF-8 byte order marks found."
exit 0
