#!/usr/bin/env bash

# Solr service for a functional test run. Sourced by runTests.sh, and meant to be
# sourced the same way from a project's own copy:
#
#   SOLR_LIB=".Build/vendor/calien/typo3-solr-testing/Build/Scripts/solr.sh"
#   [ -f "${SOLR_LIB}" ] && . "${SOLR_LIB}"
#
# Reads CONTAINER_BIN, CI_PARAMS, SUFFIX, NETWORK, IMAGE_PHP and ROOT_DIR, and
# appends to CONTAINER_COMMON_PARAMS. Nothing else of the host runner is touched,
# and no cleanup is needed: the container joins ${NETWORK}, which runTests.sh
# tears down.

SOLR_CONTAINER_NAME=""
SOLR_PORT="8983"
SOLR_SKELETON_DIR=""

solrImage() {
    case ${SOLR_DISTRIBUTION} in
        ext-solr)
            echo -n "docker.io/typo3solr/ext-solr:${SOLR_VERSION}"
            ;;
        apache)
            echo -n "docker.io/library/solr:${SOLR_VERSION}"
            ;;
    esac
}

# The plain Apache image carries no TYPO3 configset, no cores and, decisively,
# not the plugin EXT:solr registers in its solrconfig.xml
# (org.typo3.solr.search.AccessFilterQParserPlugin). Loading a core from that
# configset without it fails on the unresolvable class, so the whole skeleton is
# provisioned out of the installed extension, not just the configset.
#
# It is copied rather than mounted in place: a run must not write into the
# installed package, and must not collide with a Solr the developer already has
# open on it.
solrProvisionSkeleton() {
    local SOURCE="${ROOT_DIR}/.Build/vendor/apache-solr-for-typo3/solr/Resources/Private/Solr"

    if [ ! -d "${SOURCE}" ]; then
        echo "EXT:solr is not installed, so its Solr skeleton cannot be provisioned." >&2
        echo "Expected it at ${SOURCE} — run \"-s composerUpdate\" first." >&2
        return 1
    fi

    SOLR_SKELETON_DIR="${ROOT_DIR}/.Build/solr-${SUFFIX}"
    rm -rf "${SOLR_SKELETON_DIR}"
    mkdir -p "${SOLR_SKELETON_DIR}"
    cp -a "${SOURCE}/." "${SOLR_SKELETON_DIR}/"
    mkdir -p "${SOLR_SKELETON_DIR}/data"

    # TYPO3_SOLR_ENABLED_CORES is honoured by an entrypoint script the EXT:solr
    # image ships and this one does not, so the selection happens here instead.
    # Without it all 40 language cores load, which costs a minute of start-up.
    local CORE_DIR
    for CORE_DIR in "${SOLR_SKELETON_DIR}"/cores/*; do
        [ -f "${CORE_DIR}/core.properties" ] || continue
        case " ${SOLR_ENABLED_CORES} " in
            *" $(basename "${CORE_DIR}") "*) ;;
            *) mv "${CORE_DIR}/core.properties" "${CORE_DIR}/core.properties_disabled" ;;
        esac
    done

    # Solr runs as uid 8983 in the container. The copy is disposable and lives
    # under .Build, so widening it beats mapping users across two engines.
    chmod -R a+rwX "${SOLR_SKELETON_DIR}"
}

solrStart() {
    SOLR_CONTAINER_NAME="solr-func-${SUFFIX}"
    local IMAGE
    IMAGE="$(solrImage)"
    local MOUNT=""

    if [ "${SOLR_DISTRIBUTION}" = "apache" ]; then
        solrProvisionSkeleton || return 1
        MOUNT="-v ${SOLR_SKELETON_DIR}:/var/solr/data"
    fi

    ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name "${SOLR_CONTAINER_NAME}" --network "${NETWORK}" -d \
        -e TYPO3_SOLR_ENABLED_CORES="${SOLR_ENABLED_CORES}" \
        --tmpfs /var/solr/data/data:rw,noexec,nosuid \
        ${MOUNT} \
        "${IMAGE}" solr-foreground --user-managed >/dev/null || return 1

    CONTAINER_COMMON_PARAMS="${CONTAINER_COMMON_PARAMS} \
        -e TESTING_SOLR_SCHEME=http \
        -e TESTING_SOLR_HOST=${SOLR_CONTAINER_NAME} \
        -e TESTING_SOLR_PORT=${SOLR_PORT}"
}

# Not runTests.sh's waitFor(): that probes TCP with an ~11 second budget, while Solr
# binds its port long before it can serve, on a cold start by minutes.
#
# Waiting for the server to answer is not enough either — it reports a Lucene
# version while its cores are still opening, and a query against one of them gets
# "SolrCore is loading" (503). So this waits until as many cores are reported as
# were enabled.
solrWaitFor() {
    local STATUS_URL="http://${SOLR_CONTAINER_NAME}:${SOLR_PORT}/solr/admin/cores?action=STATUS&wt=json"
    local EXPECTED
    EXPECTED=$(echo ${SOLR_ENABLED_CORES} | wc -w)
    local TESTCOMMAND="
        COUNT=0;
        while true; do
            LOADED=\$(curl -sf \"${STATUS_URL}\" | grep -o '\"instanceDir\"' | wc -l);
            if [ \"\${LOADED}\" -ge ${EXPECTED} ]; then
                echo \"Solr served ${EXPECTED} core(s) after \${COUNT} seconds.\";
                exit 0;
            fi;
            if [ \"\${COUNT}\" -gt ${SOLR_WAIT_TIMEOUT:-180} ]; then
                echo \"Solr at ${SOLR_CONTAINER_NAME}:${SOLR_PORT} served \${LOADED} of ${EXPECTED} cores before giving up.\";
                exit 1;
            fi;
            sleep 1;
            COUNT=\$((COUNT + 1));
        done;
    "
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name "solr-wait-${SUFFIX}" ${IMAGE_PHP} /bin/sh -c "${TESTCOMMAND}"
}

solrLogs() {
    [ -n "${SOLR_CONTAINER_NAME}" ] && ${CONTAINER_BIN} logs "${SOLR_CONTAINER_NAME}" 2>&1
}
