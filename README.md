# calien/typo3-solr-testing

Solr support for TYPO3 functional tests, built on top of
[`typo3/testing-framework`](https://github.com/TYPO3/testing-framework).

The testing framework gives every test class its own database. Solr has no equivalent, so one Solr server
serves a whole run and every test class reads and writes the same core. That turns any assertion about the
index into a statement about whatever else the suite indexed. This package closes that gap:

- an **isolated Solr core per test class**, created and dropped around the test;
- **document fixtures** — `importSolrDataSet()` as the Solr counterpart of `importCSVDataSet()`, with
  `assertSolrDataSet()` alongside it;
- **`runTests.sh` building blocks** that bring a Solr container up on the test network, so a functional suite
  brings its own Solr instead of depending on whatever the developer happens to have running.

> **Status:** early development. The API is not stable yet.

## Version matrix

One branch per TYPO3 core major. Branch names and release tags follow the **core** version.

| Version | Branch | TYPO3 | EXT:solr | `typo3/testing-framework` | PHPUnit | PHP |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| 14.x | `main` | ^14.3.6 | ^14.0 | ^9.5 | ^11.5.56 | 8.2 – 8.5 |
| 13.x | `release-13.x` | ^13.4.34 | ^13.1.4 | ^8 \|\| ^9 | ^11.5.56 | 8.2 – 8.5 |

TYPO3 11 and 12 are not supported — both are end-of-life, and EXT:solr no longer ships releases for them.

Minimum versions are pinned past every known security advisory rather than to the oldest technically
compatible release, so `composer require --dev` can never resolve to a vulnerable core, PHPUnit or YAML
parser.

## Installation

```shell
composer require --dev calien/typo3-solr-testing
```

`apache-solr-for-typo3/solr` is intentionally **not** a runtime requirement — you bring your own, and the
`conflict` constraint keeps a branch from being paired with the wrong EXT:solr major.

## Usage

Extend the base test case instead of `TYPO3\TestingFramework\Core\Functional\FunctionalTestCase`:

```php
final class MySearchTest extends \Calien\SolrTesting\SolrFunctionalTestCase
{
    #[Test]
    public function indexedPageIsFound(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages.csv');
        $this->importSolrDataSet(__DIR__ . '/Fixtures/documents.yaml');

        $this->assertSolrContainsDocumentCount(3);
    }
}
```

Documents are YAML, records stay CSV. Only the fields a fixture lists are asserted, the same way
`assertCSVDataSet()` asserts only the columns present in its header.

## Requirements

A Solr server matching the EXT:solr version under test. The published
[`typo3solr/ext-solr`](https://hub.docker.com/r/typo3solr/ext-solr) image carries the TYPO3 configsets and
cores already; `Build/Scripts/solr.sh` starts one for a test run. A custom configset can be supplied — nothing
here assumes the schema EXT:solr ships.

## Continuous integration

One workflow per supported TYPO3 major, staged cheapest-first:

```
cgl     ─┐
phpstan ─┼─> unit ─> functional (sqlite) ─> functional (mariadb, mysql, postgres)
lint    ─┘
```

PHP is tested at the edges only — 8.2 and 8.5 — since the minors in between are covered by them. The DBMS
matrix only runs once the same tests passed on SQLite, so a defect that is not database specific is reported
by two jobs rather than eight. Each workflow ends in an `all checks` job whose name never changes,
which is the one a branch ruleset should require — requiring the leaf jobs by name would pin the matrix into
a repository setting that outlives the branch.

`testcore13.yml` is a placeholder on `main` reporting the same `all checks` name so one ruleset covers both
maintained branches; the real matrix lives in it on `release-13.x`.

Every CI call passes `-b docker`. `runTests.sh` prefers podman, which is the right local default, but
GitHub's runners ship both and their podman/crun pairing has been intermittently aborting the first
container start of a job since 2026-07-29. Functional jobs additionally pin `ubuntu-22.04`; everything else
runs on `ubuntu-latest`.

Both are workarounds with an unknown shelf life, so `runner-canary.yml` runs the functional suite weekly
across `ubuntu-22.04`/`24.04`/`26.04` and `ubuntu-24.04-arm`, on both engines, without gating anything. When
a cell is consistently green the gating workflow moves onto it and the lane is deleted.

## Contributing

See [DEVELOPMENT.md](DEVELOPMENT.md) for the design, the upstream analysis it rests on and the implementation
plan. Run everything through the containerised runner rather than a host PHP:

```shell
Build/Scripts/runTests.sh -s unit
Build/Scripts/runTests.sh -s functional
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
