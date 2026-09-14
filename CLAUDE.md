# calien/typo3-solr-testing

Adds Solr support to `typo3/testing-framework`: an isolated Solr core per functional test class, document
fixtures, and `runTests.sh` building blocks that bring a Solr container up on the test network.

Standing rules below. The reasoning, evidence and full plan live in [DEVELOPMENT.md](DEVELOPMENT.md) — read the
section named when a rule is not self-explanatory. If the two disagree, DEVELOPMENT.md is authoritative and
this file is the stale summary.

## Scope

- **TYPO3 13 and 14 only — two branches**, `release-13.x` and `main`. TYPO3 11 and 12 are EOL and out of scope;
  do not reintroduce them. (§2)
- Branch names and release tags follow the **core** version, not the testing-framework version. A TF major
  spans two core majors, while EXT:solr and `sbuerk/typo3-site-based-test-trait` are each 1:1 with one. (§2)
- Each branch targets exactly one live EXT:solr minor — 13.1 and 14.0 — so one pinned image tag per branch.
- Packagist name is `calien/typo3-solr-testing`. Vendor is `calien`, **without** the `666` of the GitHub org.

## Dependencies

- `apache-solr-for-typo3/solr` is a **dev dependency plus a `conflict`** — consumers bring their own, and the
  conflict range stops a branch being paired with the wrong EXT:solr major. EXT:solr's own TF pin is its dev
  dependency and never propagates. (§9)
- `typo3/testing-framework` is a **runtime `require`**: shipped classes extend `FunctionalTestCase`, and a
  dependency's `require-dev` is never installed for a consumer. Same for
  `sbuerk/typo3-site-based-test-trait`, `symfony/yaml` and `phpunit/phpunit`. (§9)
- Use `sbuerk/typo3-site-based-test-trait` — namespace `SBUERK\TYPO3\Testing\…`, not core's. Never reach into
  core's test tree. (§9)
- `sbuerk/fixture-packages` is for **our own suite only**: it adopts autoload into the *root* package's
  `autoload-dev`, so it cannot deliver anything to consumers. (§19.3)
- **No minimum may resolve to a version with a known advisory.** Check before pinning or widening —
  `https://packagist.org/api/security-advisories/?packages[]=<name>` — and pin past the highest fixed
  version, not to the oldest technically compatible one. Re-check when bumping. (§9)
- Keep the PHPUnit constraint aligned with **TYPO3 core's own dev requirement**, not with the widest range
  the testing framework permits. Core, EXT:solr and TF all converge on 11.5.x. (§9)
- `require.php` **enumerates every tested PHP minor** (`^8.2 || ^8.3 || ^8.4 || ^8.5`) even though `^8.2`
  alone is equivalent — it documents the supported matrix where someone will actually look. Do not collapse
  it, and extend it in the same commit that adds a PHP version to the CI matrix.

## Hard rules

- **Shipped code must never reference `Tests/` or `Build/`.** Both are `export-ignore`d and absent from a dist
  install. Consumer-facing fixture extensions go in `Resources/`, registered in `autoload`, never
  `autoload-dev`. (§19.2)
- **Never assume EXT:solr's shipped schema.** Validate against the live Schema API, cached **per core** —
  the configset carries one schema per language. Configset name, core names and image tag are configuration
  with defaults, never constants. (§16.6)
- **Talk to Solr over plain HTTP via `RequestFactory`, never Solarium.** Solarium's client comes from the site
  configuration under test, its errors hide Solr's, and its major differs per branch. Upstream does the same.
  (§16.9)
- **Derive only fields the live schema knows.** `appKey`, `site`, `siteHash` and `id` are EXT:solr document
  conventions, not schema facts; deriving them into a foreign schema injects fields our own validator rejects.
- **Commit to Solr explicitly.** `plugin.tx_solr.index.enableCommits = 0` is a legitimate production setting,
  so a write is never assumed visible. (§16.8)
- **Reset EXT:solr's static caches in `tearDown()`** — `ConnectionManager`, `SiteUtility`, `TwoLevelCache`.
  The symptom of missing this is *a different test class* failing with no mention of Solr. (§4)
- **Do not ship a whole `runTests.sh` as the integration mechanism.** Ship a sourceable
  `Build/Scripts/solr.sh`; the consumer's own runner sources it from `vendor/`. It may only read
  `CONTAINER_BIN`, `CI_PARAMS`, `SUFFIX`, `NETWORK` and append to `CONTAINER_COMMON_PARAMS`. (§17)
- **Our own `runTests.sh` is also a template projects copy**, so it deliberately keeps capabilities this
  branch does not need — the `-t` core switch, `phpstanGenerateBaseline`, and later a Solr version switch.
  Do not strip an option just because this repository cannot exercise it.
- **What an option accepts must mirror `composer.json`.** `-t` offers only the core majors the manifest can
  actually resolve (just `14` here); widen both together or neither.
- **Never generate or fill a PHPStan baseline yourself**, and never run `-s phpstanGenerateBaseline`. The
  suite exists because a maintainer may have good reason to use it; that decision is not yours to take.
- **A plain `apache/solr` is not a drop-in for `typo3solr/ext-solr`.** EXT:solr's `solrconfig.xml` registers
  `org.typo3.solr.search.AccessFilterQParserPlugin` from its own jar in `Resources/Private/Solr/typo3lib/`,
  reachable only via the `sharedLib` and `modules` declarations in EXT:solr's `solr.xml`. The official image
  has none of it, so core creation fails outright. Provision the whole `Resources/Private/Solr/` tree, and
  treat the two distributions as **separate CI lanes** — a green one says nothing about the other. (§17.6)
- **Never use core's `waitFor()` for Solr.** It is a TCP probe with an ~11 s budget; Solr binds the port long
  before its cores load. Poll `admin/cores?action=STATUS`. (§17.6)

## Fixtures and tests

- Solr documents are **YAML**; DB records stay **CSV** via the testing-framework's `importCSVDataSet()`.
  `symfony/yaml` is a hard requirement of `typo3/cms-core`, so it costs nothing. (§16.4)
- Import is strict: hard-fail on unknown fields, multi-value mismatches, and duplicate **resolved** ids. Then
  verify by **comparing the indexed count against the fixture count** — that backstop catches silent loss
  whatever caused it. (§16.5, §16.9)
- Tests use PHPUnit **attributes** (`#[Test]`, `#[DataProvider]`), never `@test` annotations; test classes are
  `final`. (§19.1)
- **A green check proves nothing until it has failed on purpose.** Every isolation and emptiness assertion
  needs its negative control. This is a review gate. (§19.4)

## Working conventions

- Work on a topic branch, never directly on the target branch, so commits stay squashable after pushing.
- Never run `php` or `composer` on the host — dispatch through `Build/Scripts/runTests.sh`.
