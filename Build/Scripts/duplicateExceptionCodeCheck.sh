#!/usr/bin/env bash

#
# Exception codes are unix timestamps and must be unique across the package, so
# a code found in a bug report points at exactly one throw statement.
#

set -o pipefail

THIS_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null && pwd)"
cd "${THIS_SCRIPT_DIR}/../../" || exit 1

DUPLICATES=$(
    grep -rhoE '\b1[0-9]{9}\b' Classes 2>/dev/null \
    | sort \
    | uniq -d
)

if [ -n "${DUPLICATES}" ]; then
    echo "Duplicate exception codes found:" >&2
    for CODE in ${DUPLICATES}; do
        echo "  ${CODE}" >&2
        grep -rn "${CODE}" Classes >&2
    done
    exit 1
fi

echo "No duplicate exception codes found."
exit 0
