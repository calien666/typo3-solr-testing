:navigation-title: Configuration

.. _configuration:

=============
Configuration
=============

Choosing the Solr server
========================

Two distributions are offered, selected like the DBMS is:

.. code-block:: bash

    Build/Scripts/runTests.sh -s functional                       # ext-solr, the default
    Build/Scripts/runTests.sh -s functional -S apache             # plain Apache Solr
    Build/Scripts/runTests.sh -s functional -S apache -V 9.10.1   # a specific version

.. confval:: -S
    :name: option-distribution
    :type: ext-solr | apache
    :Default: ext-solr

    `ext-solr` uses `typo3solr/ext-solr`, which carries EXT:solr's configsets,
    its language cores and the Java plugin its `solrconfig.xml` registers.

    `apache` uses a plain `library/solr`, into which that whole skeleton is
    provisioned from the installed extension.

.. confval:: -V
    :name: option-version
    :type: string
    :Default: 14.0 for ext-solr, 10.0.0 for apache

    The accepted values differ per distribution. An `ext-solr` tag must match the
    installed EXT:solr minor, because its configset name is version-stamped. An
    `apache` tag is any published one.

Why both
--------

**The default is the supported combination**: EXT:solr's own image at the version
matching the extension. A plain `runTests.sh -s functional` exercises that, and
it is what most suites should use.

The plain server exists because production usually runs a **newer** Apache Solr
than EXT:solr publishes an image for. Testing only the shipped pairing proves the
one combination nobody deploys.

.. important::
    The two are not interchangeable, and a green run on one says nothing about
    the other. EXT:solr registers a query parser —
    `org.typo3.solr.search.AccessFilterQParserPlugin` — from a jar it ships in
    :file:`Resources/Private/Solr/typo3lib/`, reachable only through the
    `sharedLib` and `modules` declarations in its own :file:`solr.xml`. A plain
    image has none of it, and creating a core from EXT:solr's configset there
    fails on the unresolvable class. That parser is the access-restriction
    filter, so any test touching content access groups depends on it.

    This is handled for you — the whole skeleton is provisioned, not just the
    configset — but it is why the plain distribution deserves its own CI lane.

Which cores load
================

.. confval:: SOLR_ENABLED_CORES
    :name: env-enabled-cores
    :type: string
    :Default: english german danish

    Space-separated **directory** names from the skeleton, as an environment
    variable.

.. warning::
    Directory names are languages (`english`, `german`); the Solr **core** names
    in :file:`core.properties` are `core_en`, `core_de`. Do not confuse them —
    this variable takes the directory name.

Loading all forty language cores costs about a minute of start-up, so keep this
to what the suite actually queries.

How the test reaches Solr
=========================

`solrStart` puts three variables into the test container's environment. A test
reads them through the base case rather than directly, but they are the seam if
you point a suite at a server you manage yourself:

.. list-table::
    :header-rows: 1

    *   -   Variable
        -   Default
    *   -   `TESTING_SOLR_SCHEME`
        -   `http`
    *   -   `TESTING_SOLR_HOST`
        -   the container name on the test network
    *   -   `TESTING_SOLR_PORT`
        -   `8983`

.. confval:: SOLR_WAIT_TIMEOUT
    :name: env-wait-timeout
    :type: int
    :Default: 180

    Seconds to wait for Solr to serve its cores before the run fails.

Using your own schema
=====================

Nothing here assumes the schema EXT:solr ships. Projects extend it routinely —
extra fields for their own record types — and a custom configset is a supported
case, not a tolerated one.

The schema is read from the **running** server through Solr's Schema API and
cached per core, so validation always matches the server the test is talking to.
It is read per core because a configset carries one schema per language, and
field sets can therefore differ between cores on one server.

To supply your own, place the configset in the skeleton that gets provisioned and
select it when the core is created. Configset name, core names and image tag are
configuration with defaults, never constants.

.. note::
    `EXT:solr`'s shipped configset uses `ClassicIndexSchemaFactory`, so its schema
    cannot change at runtime and caching it is safe. A custom configset may enable
    `ManagedIndexSchemaFactory` with `mutable="true"`; a test that alters the
    schema mid-run has to invalidate the cache explicitly.

.. tip::
    `solr.xml` declares `${solr.modules:}` as a placeholder. A schema needing
    extra Solr modules appends them through that variable rather than by editing
    the file.
