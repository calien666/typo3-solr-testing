:navigation-title: Assertions

==========
Assertions
==========

Counting
========

.. code-block:: php

    $this->assertSolrIsEmpty();
    $this->assertSolrContainsDocumentCount(3);

    // Scoped, the same way assertSolrDataSet() scopes.
    $this->assertSolrContainsDocumentCount(3, '', 'type:pages');

A field the schema indexes **without storing** is never returned by a select, so
it cannot be read back — only searched for:

.. code-block:: php

    self::assertSame(
        2,
        $this->getSolrServer()->countDocuments($this->getSolrCoreName(), 'appKey:"EXT:solr"'),
    );

`appKey` is exactly such a field in the schema EXT:solr ships.

Asserting what a search returns
===============================

`assertSolrDataSet()` takes a **Solr query**. A visitor types a **search term**,
which is a different thing: it has to be escaped so its punctuation stays text,
and it goes through the analysis chain of the core's language.

.. code-block:: php

    $this->assertSolrSearchResults('foxes', __DIR__ . '/Fixtures/expected-results.yaml');
    $this->assertSolrSearchCount('foxes', 1);

The term is escaped with EXT:solr's own `EscapeService`, the same one a frontend
search uses, so `nosuchfield:whatever` is searched for as text rather than
becoming a field lookup.

It then reaches the `/select` handler the configset defines, which means
`edismax` across `content^40 title^5 keywords^2 …` with `mm=2<-35%` — and the
language analysis of the core. Searching `fox` finds a document containing
`foxes`, which no field query would.

.. note::
    This asserts what **Solr** returns for a term. It is not what EXT:solr's
    frontend returns: the extension adds access filters, TypoScript-configured
    boosts and its own query building on top. Testing that is a layer above this
    one.

Comparing against a fixture
===========================

`assertSolrDataSet()` is to Solr what `assertCSVDataSet()` is to the database, and
takes the same YAML an import takes — see :doc:`../Fixtures/Index`:

.. code-block:: php

    $this->importSolrDataSet(__DIR__ . '/Fixtures/documents.yaml');
    // ... exercise whatever changes the index ...
    $this->assertSolrDataSet(__DIR__ . '/Fixtures/expected-documents.yaml');

Only the fields the fixture lists are compared, exactly as `assertCSVDataSet()`
compares only the columns present in its header. A Solr document carries dozens
of fields — scoring, `_version_`, timestamps — and demanding all of them would
make every assertion unmaintainable.

What is compared against
------------------------

The comparison is scoped to the **document types the fixture lists**, and the
method takes nothing else. A fixture of three `pages` is compared against every
`pages` document in the index, so six of them fail the assertion, while news
entries indexed alongside are none of that fixture's business.

One fixture may list several types, the way a CSV data set holds several tables.
Every type it names is compared against, and only those:

.. code-block:: yaml

    documents:
      -
        rootPageId: 1
        type: pages
        uid: 10
        title: 'A page'
      -
        rootPageId: 1
        type: tx_news_domain_model_news
        uid: 13
        title: 'A news entry'

Each type is fetched with **its own query**, rather than one query matching them
all — again what `assertCSVDataSet()` does with its tables. A single-clause filter
cannot trip the `mm` the request handler sets, and a failure names the type it
came from:

.. code-block:: text

    Asserting "…/expected-documents.yaml":
    Document "…/pages/12" not found in type "pages".
    Not asserted document found in type "tx_news_domain_model_news" for "…/15": {"title":"Document 15"}

.. code-block:: php

    // Three pages expected, compared against every page in the index.
    $this->assertSolrDataSet(__DIR__ . '/Fixtures/expected-pages.yaml');

If any expected document has no `type` — one given by `id` alone, for instance —
the scope widens to the whole index, keeping the stricter reading where it is
unclear what was meant.

To assert what a *search* returns rather than what the index holds, use
`assertSolrSearchResults()` above. A fixture comparison takes a fixture; it does
not take a query.

Every failure names the scope it was found in, so a surprising result shows what
was actually compared against.

`appKey` is **not** derived for an expectation, unlike for an import: an import
has to fill that field, while an assertion compares only what you wrote.

Asserting that a field is set, without pinning its value
--------------------------------------------------------

A value of `\*` asserts presence only, the way a CSV data set does. Use it for a
timestamp, or anything else a test cannot predict:

.. code-block:: yaml

    documents:
      -
        rootPageId: 1
        type: pages
        uid: 10
        title: 'A page'
        indexed: '\*'

The same shape as assertCSVDataSet()
------------------------------------

The method follows `assertCSVDataSet()` step for step, with the document type
where the CSV has a table:

.. list-table::
    :header-rows: 1

    *   -   `assertCSVDataSet()`
        -   `assertSolrDataSet()`
    *   -   Scope comes from the tables the fixture names
        -   from the document types the fixture names
    *   -   Fetches every row of those tables
        -   fetches every document of those types
    *   -   Compares only the columns in the header
        -   compares only the fields the document lists
    *   -   Matches a row by its `uid`
        -   matches a document by the schema's `uniqueKey`
    *   -   Reports an unexpected row in the fixture's own columns
        -   reports an unexpected document in the fixture's own fields
    *   -   `\*` skips the comparison of a value
        -   the same

One difference: values are compared **strictly**. The CSV data set casts both
sides to string, because a database hands back strings; Solr types a value by its
schema field, so `10` and `"10"` are genuinely different there and a comparison
that hid that would be hiding a real defect.

Three kinds of failure
----------------------

All of them are collected and reported together, so one run tells you everything
that is wrong rather than only the first thing.

.. list-table::
    :header-rows: 1

    *   -   Message
        -   Means
    *   -   `Document "<id>" not found in index.`
        -   the fixture expects a document that is not there
    *   -   `Assertion in data-set failed for "<id>":`
        -   it is there and differs; an `expected … got …` line per field follows
    *   -   `Not asserted document found for "<id>": …`
        -   the index holds a document no assertion covered

.. important::
    The third is the one that earns the method its keep. It catches
    **over-indexing**, which no positive assertion can see: a fixture asserting
    three documents passes happily while the index holds thirty. Counting alone
    does not catch it either, unless you already knew the number to expect.

Asserting an empty index counts as an assertion, so an emptiness check does not
trip PHPUnit's "test did not perform any assertions" and become a silent no-op.

Asserting an unstored field is refused
--------------------------------------

Listing a field the schema does not store fails with
`InvalidSolrDataSetException` (code 1789398800) rather than reporting a
difference that could never be satisfied. The message names the field and points
at `countDocuments()`.

Prove the assertion can fail
============================

.. warning::
    A green check proves nothing until it has failed on purpose.

Point a check at the wrong core and it reports success over an empty scan. A
vacuous assertion looks exactly like a passing test. Every isolation and
emptiness assertion needs its negative control — a case asserting the opposite —
and that is a review gate here, not a nice-to-have.

The package holds itself to this. Its own isolation pair,
`SolrCoreIsolationTest` and `SolrCoreIsolationSecondTest`, was confirmed to fail
when isolation was deliberately removed, rather than merely observed to pass.
