:navigation-title: Assertions

==========
Assertions
==========

.. warning::
    Not implemented yet. This documents the intended API.

Counting
========

.. code-block:: php

    $this->assertSolrIsEmpty();
    $this->assertSolrContainsDocumentCount(3);

Comparing against a fixture
===========================

`assertSolrDataSet()` is to Solr what `assertCSVDataSet()` is to the database,
and takes the same YAML a fixture import takes:

.. code-block:: php

    $this->assertSolrDataSet(__DIR__ . '/Fixtures/expected-documents.yaml');

Only the fields the fixture lists are compared, exactly as `assertCSVDataSet()`
compares only the columns present in its header. A Solr document carries dozens
of fields — scoring, `_version_`, timestamps — and demanding all of them would
make every assertion unmaintainable.

Three kinds of failure are reported, and all of them at once rather than the
first:

.. list-table::
    :header-rows: 1

    *   -   Message
        -   Means
    *   -   `Document "<id>" not found in index`
        -   the fixture expects a document that is not there
    *   -   `Assertion in data-set failed for "<id>"`
        -   it is there and differs; a field-by-field diff follows
    *   -   `Not asserted document found for "<id>"`
        -   the index holds a document no assertion covered

.. tip::
    The third is the valuable one. It is what catches **over-indexing**, which no
    positive assertion can see: a test asserting three documents exist passes
    happily while the index holds thirty.

Asserting an empty index does not trip PHPUnit's "test did not perform any
assertions", so an emptiness check is a real assertion rather than a silent
no-op.

Prove the assertion can fail
============================

.. warning::
    A green check proves nothing until it has failed on purpose.

Point a check at the wrong core and it reports success over an empty scan. A
vacuous assertion looks exactly like a passing test. Every isolation and
emptiness assertion needs its negative control — a case asserting the opposite —
and that is a review gate here, not a nice-to-have.
