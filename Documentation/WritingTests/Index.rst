:navigation-title: Writing tests

=============
Writing tests
=============

.. note::
    The base test case, the core lifecycle, the fixture import and the count
    assertions are implemented. `assertSolrDataSet()`, described in
    :doc:`../Assertions/Index`, is not yet.

The base test case
==================

Extend `SolrFunctionalTestCase` instead of the testing framework's
`FunctionalTestCase`:

.. code-block:: php

    <?php

    declare(strict_types=1);

    namespace MyVendor\MyExtension\Tests\Functional;

    use Calien\SolrTesting\SolrFunctionalTestCase;
    use PHPUnit\Framework\Attributes\Test;

    final class SearchTest extends SolrFunctionalTestCase
    {
        protected array $testExtensionsToLoad = [
            'apache-solr-for-typo3/solr',
            'my-vendor/my-extension',
        ];

        #[Test]
        public function indexedPageIsFound(): void
        {
            $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
            $this->importSolrDataSet(__DIR__ . '/Fixtures/documents.yaml');

            $this->assertSolrContainsDocumentCount(3);
        }
    }

What the base case does for you
===============================

Its own core
------------

Each test class gets a Solr core created before the test and dropped afterwards,
named from the same identifier the testing framework uses for the database. Two
classes therefore never share an index, and a class that fails does not leave
documents behind for the next one.

Static state is reset
---------------------

EXT:solr keeps three caches in `static` properties. `static` is not a singleton,
so `GeneralUtility::purgeInstances()` does not touch them and they survive for
the whole PHPUnit process:

.. list-table::
    :header-rows: 1

    *   -   Property
        -   What leaks
    *   -   `SiteUtility::$languages`
        -   The language configuration, with the resolved core name in it
    *   -   `TwoLevelCache::$firstLevelCache`
        -   Whole site objects, including their connection configuration
    *   -   `ConnectionManager::$connections`
        -   Connections, keyed by a hash of the node configuration

The base case resets all three in `tearDown()`.

.. tip::
    This is the failure the package exists to prevent, and it never looks like a
    Solr problem. The symptom is **a different test class failing**, with no
    mention of Solr or caching in the message, and passing again when run alone.
    If a test passes alone and fails in the suite, run the suspected pair
    together and confirm the order matters before touching any assertion.

Sites are configured
--------------------

`writeDefaultSolrTestSiteConfiguration()` writes site configurations pointing at
the test server and the class's own core, so a test does not have to know the
host, port or core name.

Writing and emptying
====================

.. code-block:: php

    $this->cleanUpSolrServerAndAssertEmpty();
    $this->waitToBeVisibleInSolr();

.. warning::
    Always commit explicitly. `plugin.tx_solr.index.enableCommits = 0` is a
    legitimate production setting, so a helper can never assume a write has
    become visible.

A core is per class, not per test method, so a class with several methods still
has to empty the index between them.
