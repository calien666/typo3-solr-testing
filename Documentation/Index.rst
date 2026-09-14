:navigation-title: Solr testing

==================================================
Solr testing for TYPO3 — calien/typo3-solr-testing
==================================================

:Extension key:
    typo3-solr-testing

:Package name:
    calien/typo3-solr-testing

:Version:
    |release|

:Language:
    en

:Author:
    calien

:License:
    This document is published under the
    `Creative Commons BY 4.0 <https://creativecommons.org/licenses/by/4.0/>`__
    license.

----

Functional testing against Apache Solr, built on
:t3coreapi:`typo3/testing-framework <Testing/Index>`. Gives a test class its own
Solr core, loads documents from fixtures, asserts what the index contains, and
starts the Solr server the suite needs.

This manual is written for developers testing an extension or a project that
builds on :t3solr:`EXT:solr <Index>`.

----

.. toctree::
    :maxdepth: 2
    :titlesonly:

    Introduction/Index
    Installation/Index
    WritingTests/Index
    Fixtures/Index
    Assertions/Index
    Configuration/Index
