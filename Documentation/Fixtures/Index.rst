:navigation-title: Fixtures

=============
Fixture files
=============

.. warning::
    Not implemented yet. This documents the intended format.

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

Format
======

.. code-block:: yaml

    documents:
      -
        rootPageId: 100
        type: news
        uid: 101
        title: 'Hidden news page'

A document states `type`, `uid` and `rootPageId`. The rest is derived:

.. list-table::
    :header-rows: 1

    *   -   Field
        -   Derived from
    *   -   `appKey`
        -   always `EXT:solr`
    *   -   `site`, `siteHash`
        -   the resolved site
    *   -   `id`
        -   `<siteHash>/<type>/<uid>`

.. tip::
    Name the site by its **root page**, never by a domain. A project serves many
    sites, and a domain copied into a fixture stays right only until someone
    changes that site's base.

`siteHash` and `id` stay overridable. Two cases need it: a record indexed as one
document per occurrence carries the occurrence in its id, and a test about an
unclaimed `siteHash` needs a document belonging to no site at all.

A document does **not** have to match a record. A document whose record does not
exist is exactly what a test about stale index entries needs, and no record
fixture can express it.

What the import rejects
=======================

The import is strict, for the same reason `importCSVDataSet()` is. Its
strictness comes from TCA and the database schema; this one validates against
Solr's Schema API, read from the core under test and cached per core.

Import fails on:

*   a missing `type`, `uid` or `rootPageId` — the `id` cannot be derived
*   a missing schema-`required` field, or the `uniqueKey`
*   an **unknown field**, matching neither a declared field nor a dynamic pattern
*   an **array for a single-valued field**
*   a **scalar for a multi-valued field**
*   a type mismatch against `pint`, `pdate` or `boolean`
*   a **duplicate resolved id**

.. note::
    The last two are the reason the check exists. Solr *accepts* a scalar for a
    multi-valued field and quietly wraps it, and it *overwrites* silently on a
    duplicate id — so a fixture indexes fewer documents than it lists and nothing
    reports it. The duplicate check runs on the **resolved** id: two documents
    can look distinct and still derive the same one.

.. warning::
    Unknown-field detection is not typo-proof. `titel_stringS` matches the
    dynamic pattern `*_stringS` and is accepted. Only fields matching nothing at
    all are caught.

After the import, the number of documents in the index is compared with the
number the fixture declared, and a mismatch is diagnosed rather than merely
reported. That backstop catches silent loss whatever caused it.

Seeding instead of indexing
===========================

Indexing a page means rendering it, which drags the whole site package into the
test. Generate the documents and the record fixture from **one** definition
instead, and write them together: a document only means something next to the
record it claims to describe.

.. warning::
    Never build a seed by exporting a real installation. A production index
    carries editorial text, author names and addresses. Invent the content.
