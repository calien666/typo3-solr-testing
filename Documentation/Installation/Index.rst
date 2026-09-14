:navigation-title: Installation

============
Installation
============

Install the package
===================

.. code-block:: bash

    composer require --dev calien/typo3-solr-testing

EXT:solr is deliberately **not** a runtime requirement. You bring your own, and a
`conflict` constraint prevents a branch being paired with the wrong EXT:solr
major.

.. _installation-runtests:

Add Solr to your runTests.sh
============================

The functional suite needs a Solr server on the same container network as the
test container. This package ships that as a sourceable shell fragment rather
than as a complete :file:`runTests.sh`, so your runner stays yours and you can
keep re-syncing it against TYPO3 core.

Add two lines near the top of your :file:`Build/Scripts/runTests.sh`, after the
container engine and network have been determined:

.. code-block:: bash

    SOLR_LIB=".Build/vendor/calien/typo3-solr-testing/Build/Scripts/solr.sh"
    [ -f "${SOLR_LIB}" ] && . "${SOLR_LIB}"

The guard keeps the `composer` suites working before :file:`vendor/` exists.

Then start the server in the `functional` case, before the test container runs:

.. code-block:: bash

    functional)
        if ! solrStart || ! solrWaitFor; then
            echo "Solr did not come up. Container log follows." >&2
            solrLogs >&2
            SUITE_EXIT_CODE=1
            printSummary
        fi
        # ... your existing DBMS handling and phpunit call

That is the whole integration. No cleanup is needed: the container joins
`${NETWORK}`, and the `cleanUp()` function your runner already has kills
everything attached to it.

What the fragment expects
-------------------------

It reads five variables your runner already defines, and appends to one:

.. list-table::
    :header-rows: 1

    *   -   Variable
        -   Used for
    *   -   `CONTAINER_BIN`
        -   `podman` or `docker`
    *   -   `CI_PARAMS`
        -   CI-only container flags
    *   -   `SUFFIX`
        -   unique per run, used in the container name
    *   -   `NETWORK`
        -   the test network the container joins
    *   -   `ROOT_DIR`
        -   repository root, for provisioning
    *   -   `CONTAINER_COMMON_PARAMS`
        -   **appended to**, with the connection environment

This is the same seam TYPO3 core's own Redis and Memcached services use, so it
is about as stable as anything in that script gets.

Functions it provides
---------------------

.. list-table::
    :header-rows: 1

    *   -   Function
        -   Effect
    *   -   `solrStart`
        -   Starts the container and adds `TESTING_SOLR_SCHEME`,
            `TESTING_SOLR_HOST` and `TESTING_SOLR_PORT` to the test container's
            environment
    *   -   `solrWaitFor`
        -   Blocks until Solr **serves its cores**, not merely until it accepts a
            connection
    *   -   `solrLogs`
        -   Prints the container log, for a failure message

.. warning::
    Do not use your runner's own `waitFor()` for Solr. It probes TCP with a
    budget of roughly eleven seconds, and Solr binds its port long before it can
    answer — on a cold start by minutes. Waiting for the server to answer is not
    enough either: it reports a Lucene version while its cores are still opening,
    and a query then fails with `SolrCore is loading`.

Run it
======

.. code-block:: bash

    Build/Scripts/runTests.sh -s functional

See :ref:`configuration` for choosing the Solr distribution and version.
