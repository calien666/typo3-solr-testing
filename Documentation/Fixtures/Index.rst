:navigation-title: Fixtures

=============
Fixture files
=============

Two formats, by shape
=====================

Records stay **CSV**, loaded with the testing framework's own
`importCSVDataSet()`. Solr documents are **YAML**, loaded with
`importSolrDataSet()`.

That is not inconsistency. A database row is rectangular and CSV fits it. A Solr
document is not: multi-valued fields are normal, documents of different types
share few fields, and the testing framework's CSV format has no syntax for an
array in a cell. YAML also carries comments, which matters because several
fields are derived and want an explanation next to them.

`symfony/yaml` is a hard requirement of `typo3/cms-core`, so the parser costs
nothing.

File format
===========

A fixture is a mapping with a single `documents` key holding a list. Every entry
is one Solr document.

.. code-block:: yaml
    :caption: Tests/Functional/Fixtures/documents.yaml

    documents:
      -
        rootPageId: 1
        type: pages
        uid: 10
        title: 'A page'
      -
        rootPageId: 1
        type: tx_news_domain_model_news
        uid: 11
        title: 'A news entry'
        keywords:
          - alpha
          - beta

Load it in a test:

.. code-block:: php

    $this->importSolrDataSet(__DIR__ . '/Fixtures/documents.yaml');

Keys you write
--------------

.. confval:: rootPageId
    :name: fixture-rootpageid
    :type: int
    :Required: yes, unless `siteHash` or `id` is given outright

    The **root page of the site** the document belongs to. It is a control key:
    it is not written to Solr, it only resolves the site.

    .. tip::
        Name the site by its root page, never by a domain. A project serves many
        sites, and a domain copied into a fixture stays right only until someone
        changes that site's base.

    The site is resolved with `SiteFinder::getSiteByRootPageId()`, so the site
    configuration has to exist — but the page record itself does not, which keeps
    a document fixture usable without a matching `pages` row.

.. confval:: type
    :name: fixture-type
    :type: string
    :Required: yes, unless `id` is given outright

    The record type, as EXT:solr indexes it — `pages`, or a table name.

.. confval:: uid
    :name: fixture-uid
    :type: int
    :Required: yes, unless `id` is given outright

    The uid of the record this document describes.

Every other key is written to Solr as a field of that name.

Keys that are derived
---------------------

.. list-table::
    :header-rows: 1

    *   -   Field
        -   Derived as
        -   Override it when
    *   -   `appKey`
        -   always `EXT:solr`
        -   never, in practice
    *   -   `siteHash`
        -   from the site `rootPageId` resolves to
        -   testing a document belonging to no site
    *   -   `id`
        -   `<siteHash>/<type>/<uid>`
        -   the real id carries more, as for a record indexed once per occurrence

Writing a derived key yourself always wins. Giving `id` outright makes
`rootPageId`, `type` and `uid` unnecessary:

.. code-block:: yaml

    documents:
      -
        # A document belonging to no site at all, for a stale-entry test.
        id: 'orphaned/pages/99'
        siteHash: 'no-site-claims-this'
        type: pages
        uid: 99

A document does **not** have to match a record. A document whose record does not
exist is exactly what a test about stale index entries needs, and no record
fixture can express it.

Multi-valued fields
-------------------

Write a YAML list. The schema decides which fields are multi-valued, and the
import checks both directions:

.. code-block:: yaml

    keywords:
      - alpha
      - beta

What the import rejects
=======================

The import is strict, for the same reason `importCSVDataSet()` is. Its
strictness comes from TCA and from the database refusing an unknown column; this
one validates against Solr's Schema API, read from the core under test and cached
per core.

.. list-table::
    :header-rows: 1

    *   -   Rejected
        -   Exception code
    *   -   The file does not exist or cannot be read
        -   1789398380
    *   -   Two documents resolve to the same `id`
        -   1789398381
    *   -   The file has no `documents` list
        -   1789398382
    *   -   A field the schema declares neither directly nor as a dynamic pattern
        -   1789398383
    *   -   A single value for a field the schema declares multi-valued
        -   1789398384
    *   -   A list for a field the schema declares single-valued
        -   1789398385
    *   -   An entry that is not a mapping
        -   1789398386
    *   -   No `id`, and no `siteHash`, `type` or `uid` to derive one from
        -   1789398387

.. important::
    Two of those are the reason the check exists at all, because Solr does not
    report them:

    *   it **accepts a single value for a multi-valued field** and quietly wraps
        it, leaving fixture and index disagreeing with no error anywhere;
    *   it **overwrites silently on a repeated id**, so the index holds fewer
        documents than the file lists.

    The duplicate check runs on the **resolved** id. Two documents can look
    entirely distinct in the file and still derive the same one — the same
    `type` and `uid` under the same site — and that is the version most likely to
    be written by accident.

After the import, the number of documents in the index is compared with the
number the fixture declared. That backstop catches silent loss whatever caused
it, not only the causes listed above.

.. warning::
    **Unknown-field detection does nothing against the schema EXT:solr ships.**
    That schema declares a `*` dynamic field, so every possible field name matches
    something and no name is ever unknown. A typo like `titel` is accepted and
    indexed.

    The check still guards a custom schema that has no catch-all pattern. Do not
    rely on it with the stock schema.

Seeding instead of indexing
===========================

Indexing a page means rendering it, which drags the whole site package into the
test. Generate the documents and the record fixture from **one** definition
instead, and write them together: a document only means something next to the
record it claims to describe.

.. warning::
    Never build a seed by exporting a real installation. A production index
    carries editorial text, author names and addresses. Invent the content.
