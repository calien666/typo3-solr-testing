:navigation-title: Introduction

============
Introduction
============

What it does
============

`typo3/testing-framework` gives every functional test class its own database
schema. Solr has no equivalent: one server serves the whole run, so every test
class reads and writes the same core. That turns any assertion about the index
into a statement about whatever else the suite indexed, and a `deleteByQuery`
that is not scoped by `siteHash` becomes dangerous rather than merely wrong.

This package closes that gap:

*   an isolated Solr core per test class, created and dropped around the test
*   document fixtures — an import that is to Solr what `importCSVDataSet()` is to
    the database, with an assertion alongside it
*   a Solr container for the functional suite, so the tests bring their own
    server instead of depending on whatever happens to be running

Who it is for
=============

Developers writing functional tests for an extension or a project that builds on
EXT:solr. It is a development dependency and ships nothing that runs in
production.

.. note::
    This manual documents the package from the outside. The design reasoning,
    the upstream analysis it rests on and the implementation plan live in
    :file:`DEVELOPMENT.md` in the repository.

Supported versions
==================

One branch per TYPO3 core major. Branch names and release tags follow the
**core** version.

.. list-table::
    :header-rows: 1

    *   -   Version
        -   Branch
        -   TYPO3
        -   EXT:solr
        -   PHP
    *   -   14.x
        -   `main`
        -   ^14.3.6
        -   ^14.0
        -   8.2 – 8.5
    *   -   13.x
        -   `release-13.x`
        -   ^13.4.34
        -   ^13.1.4
        -   8.2 – 8.5

TYPO3 11 and 12 are not supported. Both are end-of-life, and EXT:solr no longer
ships releases for them.

Minimum versions are pinned past every known security advisory rather than to
the oldest technically compatible release.
