# Developing calien/typo3-solr-testing

Everything the package is built on: the cross-version reading of `typo3/testing-framework` and EXT:solr
(§1–§11), the field evidence that motivated it (§12), and the implementation plan (§13–§21).

`CLAUDE.md` carries the standing rules distilled from this document. When the two disagree, this one is the
reasoning and `CLAUDE.md` is the summary — fix the summary.

Upstream sources, all read at these exact refs:

Sources, all read at these exact refs:

| Repository | Refs |
| :--- | :--- |
| `TYPO3/testing-framework` | `7`@b6819fc, `8`@d4d19e8, `9`@cdb0521, `main`@4c26e8e |
| `TYPO3-Solr/ext-solr` | `release-11.5.x`, `11.6.x`@9dbb761, `12.0.x`@9953f36, `12.1.x`@20d79a2, `13.0.x`@e166e45, `13.1.x`@77101a6, `main`@63aff0a |
| `sbuerk/typo3-site-based-test-trait` | Packagist metadata, branches `1`, `2`, `main` |
| `TYPO3/typo3` | `Build/Scripts/runTests.sh` @ `main` |


## 1. The version matrix

| TF branch | alias | TYPO3 | PHP | PHPUnit |
| :--- | :--- | :--- | :--- | :--- |
| `7` | 7.1.x | 11 + 12 | >= 7.4 | ^9.5.25 \|\| ^10.1 |
| `8` | 8.3.x | 12 + 13 | ^8.1 | ^10.1 \|\| ^11.0 |
| `9` | 9.x-dev | 13 + 14 | ^8.2 | ^11.2.5 \|\| ^12.1.2 \|\| ^13.0.2 |
| `main` | **10.x-dev** | 14 + 15 | ^8.2 | as 9 |

| EXT:solr | TYPO3 | Solarium | Apache Solr | configset | own TF pin | status |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| 11.5 | 11.5 | 6.2.3 | — | `ext_solr_11_5_0` | ^6.12 | out of scope |
| 11.6 | 11.5 | 6.2.4 | 9.10.1 | `ext_solr_11_6_0_elts` | ^6.12 | out of scope, **ELTS** |
| 12.0 | 12.4 | 6.4.1 | 9.3–9.10 | `ext_solr_12_0_0` | ^8.0 | **branch closed** |
| 12.1 | 12.4 | 6.4.1 | 9.10.1 | `ext_solr_12_1_0` | ^8.0 | out of scope, **EOL** |
| 13.0 | 13.4 | 6.4.1 | 9.7–9.10 | `ext_solr_13_0_0` | ^9.0.1 | **branch closed** |
| 13.1 | 13.4 | 6.4.1 | 9.10.1 | `ext_solr_13_1_0` | ^9.0.1 | live |
| 14.0 (`main`) | 14.3 | **7.0.0** | **10.0.0** | `ext_solr_14_0_0` | ^9.5.0 | live |

Consequences:

- TF `main` is already **10.x-dev**, so the original "TF 7, 8, 9, main" reads as majors 7, 8, 9, 10.
- EXT:solr's own `typo3/testing-framework` pin is a **dev dependency of EXT:solr** and never propagates into a
  consumer's graph.
- The newest EXT:solr (14.0) still pins TF `^9.5`. There is no EXT:solr release that needs TF 10, so a
  TF-10-keyed branch would have been empty on creation.
- **The `.0` lines are explicitly dead.** EXT:solr's own release notes state *"The release-12.0.x branch is now
  closed—no further 12.0.x releases will be issued"* and the same for 13.0.x. So each branch of this package
  targets exactly **one** live EXT:solr minor — which means exactly one pinned container image tag per branch,
  with no ambiguity.
- **TYPO3 11 and 12 are both out of scope.** Core is EOL for both. EXT:solr 11.6 is an ELTS line (the configset
  is literally named `ext_solr_11_6_0_elts`) maintained outside the public repository, and EXT:solr has declared
  12 dead in its security announcement for that line. **Only 13.1 and 14.0 remain**, so this package has exactly
  two branches.

## 2. Branch axis — settled

**One branch per TYPO3 core major**, following EXT:solr. Branch names and release tags follow the core version.

| Branch | TYPO3 | EXT:solr | TF | `sbuerk/…-trait` | PHP | status |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `release-13.x` | 13.4 | 13.1 | ^8 \|\| ^9 | ^2.0 | ^8.2 | back-port |
| `main` | 14.3 | 14.0 | ^9 | ^3.0 | ^8.2 | first target |

**Two branches. That is the whole matrix.** TYPO3 11 and 12 are both EOL — EXT:solr 11.6 is ELTS outside the
public repository, and EXT:solr declared 12 dead in the security announcement for that line.

Why this axis and not the TF major — the reasoning still holds and still matters for the next core major:

- A TF major spans **two** core majors; an EXT:solr major and a `sbuerk` trait major are each **1:1** with a core
  major. Keying on TF would force a branch to carry two EXT:solr majors and two trait majors, and make each
  EXT:solr major reachable from two branches.
- Section 3 shows the divergence in the test base is driven by the **EXT:solr** major, not the TF major.

`main` becomes `release-14.x` when TYPO3 15 opens, and `release-15.x`/`main` is cut then.

Two side effects of the narrowed scope worth naming, because both were open problems an hour ago:

- `sbuerk/typo3-site-based-test-trait` has no v11 line. Irrelevant now — 2.0.x and 3.0.0 cover both branches.
- PHP floor is `^8.2` on **both** branches, so there is no PHP-version divergence to design around at all.

## 3. What the package owns: stable core vs per-major surface

Method surface of `Tests/Integration/IntegrationTestBase.php` (`IntegrationTest.php` on 11.6), diffed across all
five EXT:solr branches. Roughly two thirds is stable and is the real payload of this package:

`assertSolrIsEmpty` · `assertSolrContainsDocumentCount` · `cleanUpSolrServerAndAssertEmpty` ·
`cleanUpAllCoresOnSolrServerAndAssertEmpty` · `waitToBeVisibleInSolr` · `validateTestCoreName` ·
`getSolrConnectionInfo` · `getSolrConnectionUriAuthority` · `writeDefaultSolrTestSiteConfiguration(ForHostAndPort)` ·
`failWhenSolrDeprecationIsCreated` · `addTypoScriptConstantsToTemplateRecord` ·
`addSimpleFrontendRenderingToTypoScriptRendering` · `addPageToIndexQueue` · `getIndexQueueItem` ·
`processEventQueue` · `indexPages`

The remaining third splits along **EXT:solr majors**:

| EXT:solr | Indexing approach in the test base | |
| :--- | :--- | :--- |
| 11.6 | TSFE faking — `fakeTSFE`, `getConfiguredTSFE`, `getFakeObjectManager`, `fakeBEUser`, `importDataSetFromFixture`, `simulateFrontedUserGroups`, `applyUsingErrorControllerForCMS`. No sub-request pipeline. | *out of scope* |
| 12.1 | `executePageIndexer` + `indexPageQueueItem` over the TF frontend sub-request. | *out of scope* |
| **13.1** | `executePageIndexer` + `indexPageQueueItem` over the TF frontend sub-request. | `release-13.x` |
| **14.0** | `IndexingService`, `IndexingInstructions`, `BeforeIndexingSubRequestIsPreparedEvent`, plus paratest core sharding (`resolveCoreName`, `getParatestWorkerToken`, `getInstanceIdentifier`/`getInstancePath` overrides, `getSolrCoreUrl`). | `main` |

Verified: `Classes/IndexQueue/IndexingService.php`, `Classes/IndexQueue/IndexingInstructions.php` and
`Classes/Event/Indexing/BeforeIndexingSubRequestIsPreparedEvent.php` **do not exist** on 13.1 or earlier.

So the package ships **exactly two indexing strategies, one per branch** — the sub-request pipeline on 13.1 and
the `IndexingService` pipeline on 14.0. The TSFE-faking world is gone with TYPO3 11.

This is the `typo3-core-version-compatibility` pattern: a stable base plus one indexing-strategy implementation
per branch behind an interface. Because we branch per core major, each branch carries exactly one implementation
— no runtime version branching anywhere. With only two branches, the interface earns its keep mainly as the
seam the next core major plugs into, not as present-day abstraction.

## 4. Static state — upstream and the field evidence agree, from opposite directions

§12 identified three leaking statics empirically on 11.6. Upstream EXT:solr later added
reset APIs for **exactly those three**. Verified per branch:

| Static | 11.6 | 12.0 – 13.1 | 14.0 |
| :--- | :--- | :--- | :--- |
| `SiteUtility::$languages` | `public static`, no reset | `private static` + `SiteUtility::reset()` | same |
| `TwoLevelCache::$firstLevelCache` | `protected static`; only `->flush()` per cache name | same | + `TwoLevelCache::flushAllCaches()` |
| `ConnectionManager::$connections` | `protected static`, keyed by `md5(json_encode(read).json_encode(write))` | same | + `ConnectionManager::resetConnections()` |

So EXT:solr 14's `tearDown()` is:

```php
ConnectionManager::resetConnections();
SiteUtility::reset();
TwoLevelCache::flushAllCaches();
```

and `release-13.x` must emulate it. That is now the **only** emulation case in the package:

- `release-13.x` — `SiteUtility::reset()` already exists; `TwoLevelCache` needs the per-cache-name
  `GeneralUtility::makeInstance(TwoLevelCache::class, 'runtime')->flush()`, which does clear the static
  (verified: `self::$firstLevelCache[$this->cacheName] = []`); `ConnectionManager` needs a reflection reset or
  can be left alone, since the connection hash contains the core and a changed core yields a new connection.

The 11.6 column is retained above only because it is the context §12 was written in, and explains why
§12 had to reset `SiteUtility::$languages` by hand — the property is `public static` there, with no
`reset()`.

This reset belongs in the package's `tearDown()`, not in consumer tests. It is the single highest-value thing the
package does, because the failure mode — per §12 — is *another* test class failing with no mention of Solr.

## 5. Core isolation — the one genuinely open design question

Two strategies are on the table, and they are not variants of each other:

**A — core per test class, created at runtime** (§12). CoreAdmin `CREATE`/`UNLOAD` against an existing
configset, core named `'core_de_' . substr(sha1(static::class), 0, 10)` to mirror TF's own instance identifier
(verified: `FunctionalTestCase::getInstanceIdentifier()` is `substr(sha1(static::class), 0, 7)` on TF 7). Measured
at ~140 ms per core. Gives true per-class isolation and matches how TF isolates the database.

**B — pre-baked static cores, sharded per parallel worker** (EXT:solr 14 upstream). Cores `core_en`/`core_de`/
`core_da` exist in the image; `tests_copy-cores-for-paratest.sh` clones them to `core_en_1`, `core_en_2`, … and
`resolveCoreName()` maps a logical name to the worker's copy via `TEST_TOKEN`. Isolation is per **worker**, not
per class, so sequential classes on one worker still share a core.

A is strictly stronger and is the better fit for a package whose whole purpose is isolation; B is what upstream
does and is what EXT:solr's own suite will keep using. They compose: A can run on top of B's image without the
paratest cloning, since A creates what it needs. Recommendation is A, with the core name derived from
`static::getInstanceIdentifier()` so it tracks whatever TF does, and B's `TEST_TOKEN` suffix appended only when
running under paratest.

**§17.6 settles this.** Supporting the official `apache/solr` image alongside EXT:solr's own makes A the only
workable choice: B depends on `tests_copy-cores-for-paratest.sh`, an entrypoint script that exists solely in
the EXT:solr image, while CoreAdmin works against any Solr.

Open sub-question: A needs a configset name, and configset names are version-stamped (§6).

## 6. The Solr container: image, cores, configset

EXT:solr publishes **`typo3solr/ext-solr`** to Docker Hub, tagged per branch alias and semver (`13.1`, `13.1.4`,
`13.1.x-dev`, `latest`). We never build or ship Solr configuration — we pin a tag.

Verified from `Docker/SolrServer/Dockerfile` on both `release-11.6.x` (`FROM solr:9.10.1`) and `main`
(`FROM solr:10.0.0`):

- `COPY --chown=solr:solr Resources/Private/Solr/ /var/solr/data` — **the whole skeleton, all 40 language cores
  and the configset, is baked into the image.**
- `docker-entrypoint-initdb.d/disable-cores.sh` is wrapped in `if [ -n "${TYPO3_SOLR_ENABLED_CORES}" ]`, so with
  that variable **unset it is a no-op and all 40 cores load**.
- Ships `healthcheck.sh`, which pings `/solr/<core>/admin/ping` for every core it finds.
- Core **directory** names are languages (`english`, `german`, `danish`); the Solr **core** names in
  `core.properties` are `core_en`, `core_de`, `core_da`. Do not conflate them — `TYPO3_SOLR_ENABLED_CORES` takes
  the directory names.
- PHP side reads `TESTING_SOLR_SCHEME` / `TESTING_SOLR_HOST` / `TESTING_SOLR_PORT`.

### Reconciling the "zero cores" observation

§12 records that the image "comes up with zero cores" unmounted, and concludes the cores must come from a
mounted skeleton. That was observed inside a **DDEV project on TYPO3 11.5 with EXT:solr 11.6**, where a
`.ddev/solr` skeleton is mounted by convention — and a bind mount at `/var/solr` or `/var/solr/data`
**shadows** the baked-in skeleton, which reproduces exactly that symptom. The Dockerfile says the skeleton is in
the image, and `disable-cores.sh` cannot empty it on its own.

So the observation is consistent with its context rather than wrong, but it does not generalise to a bare
`docker run` of the image. Worth one re-test with no mount at all before we design around it: if the skeleton is
already there, the whole "copy the skeleton per run" step and its permission problems disappear for this package,
even though a DDEV project still needs its own mount.

### Configset naming is the real trap

Configset names are version-stamped: `ext_solr_11_5_0`, `ext_solr_11_6_0_elts`, `ext_solr_12_1_0`,
`ext_solr_13_1_0`, `ext_solr_14_0_0`. §12 hit this as drift — a `.ddev/solr` copy carrying
`ext_solr_11_0_0` against an installed 11.6 carrying `ext_solr_11_6_0_elts`, while pulling image tag `:11.5`
(whose configset is `ext_solr_11_5_0`). Three versions, all different.

So the rule is stronger than "don't hardcode the configset":

1. **The image tag must match the installed EXT:solr minor.** Otherwise the configset the extension expects is
   not the one the image has.
2. **Discover the name at runtime, from the running Solr** — `GET /solr/admin/configs?action=LIST` — rather than
   from any file on disk. That is the only source that cannot drift from the container we are actually talking to,
   and it needs no mount. Fall back to reading `core.properties` from the *installed extension* only if the API
   is unavailable.

## 7. runTests.sh integration

Core's `runTests.sh` service pattern extends cleanly: start `solr-func-${SUFFIX}` on `${NETWORK}`, then pass
`-e TESTING_SOLR_HOST=solr-func-${SUFFIX} -e TESTING_SOLR_PORT=8983` into the functional container.

The upstream reading and the field evidence found the same blocker independently, and §12 has the sharper
version of it:

- **`waitFor()` is unusable here.** It is a TCP probe (`nc -z`) with a ~11 s budget. Solr binds 8983 long before
  its cores load, and on a cold start needs far longer than 11 s. Poll `admin/cores?action=STATUS` for the
  specific core name, budget ~2 minutes. Getting this wrong surfaces as
  `Interface "Psr\Http\Client\ClientExceptionInterface" not found` — a connection exception failing to construct.
- **Fully qualified image name** — podman rejects `typo3solr/ext-solr:11.5` with *"short-name did not resolve to
  an alias"*. Use `docker.io/typo3solr/ext-solr:…`.
- **`--tmpfs /var/solr/data/data`** — Solr writes the index as its own container uid, which the host cannot then
  remove (`podman unshare rm -rf` otherwise). Matches EXT:solr CI, which puts the Solr data dir on ramfs anyway.
- **A seed loader's exit code must be fatal**, or the suite runs green against an empty index.

`IMAGE_SOLR` is pinned per branch, which the per-core-major branch axis makes trivial — one branch, one tag.

## 8. Fixtures and seeding

`importSolrDataSet()` as the Solr counterpart of `importCSVDataSet()` is entirely new capability; EXT:solr has
nothing like it. §12 specifies it well — YAML for the comments, documents keyed by root page rather than
domain, `siteHash`/`id` derived but overridable, documents deliberately allowed to have no matching record.

Two supporting facts verified here:

- `siteHash = sha1($domain . $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] . 'tx_solr')` is deterministic
  because TF pins `encryptionKey` to `i-am-not-a-secure-encryption-key`
  (`FunctionalTestCase.php:398` on TF 7). Still resolve it through `SiteHashService` rather than repeating the
  formula.
- Solr's JSON update endpoint takes one document per `add` key; post a bare JSON array to `/update` instead, with
  `delete` and `commit` as separate requests.

## 9. Dependencies and packaging

- `apache-solr-for-typo3/solr` is a **dev dependency**, so consumers bring their own and we never force a
  version on them. It is paired with a `conflict` range (`<14.0 || >=15.0` on `main`), because nothing else
  prevents pulling the v13 branch alongside EXT:solr 12.
- `typo3/testing-framework` is a **runtime `require`**, not a dev dependency. An earlier draft had both as
  dev-only, which is wrong for TF: shipped classes extend `FunctionalTestCase`, and **a dependency's
  `require-dev` is never installed for a consumer** — Composer resolves dev requirements of the root package
  only. Declaring it leaves the coupling undeclared but working by accident, until it isn't. The same reasoning
  puts `sbuerk/typo3-site-based-test-trait`, `symfony/yaml` and `phpunit/phpunit` in `require`: all three are
  used directly by shipped code.
  `symfony/yaml` arrives with `typo3/cms-core` anyway, but a direct dependency is declared, not inherited.

### Minimums are advisory-driven, not compatibility-driven

A constraint like `^14.3` is the *oldest technically compatible* release, which is rarely the oldest **safe**
one. Checked against `packagist.org/api/security-advisories/`, two of the first-draft constraints resolved to
vulnerable versions:

| Package | Naive | Advisory | Pinned |
| :--- | :--- | :--- | :--- |
| `typo3/cms-core` | `^14.3` | PKSA-hf64-fs5s-6k3h affects `>=14.0.0,<14.3.6` (TYPO3-CORE-SA-2026-021) | `^14.3.6` |
| `symfony/yaml` | `^7.1` | CVE-2026-45133 / 45304 / 45305 affect everything below `7.4.12` — the whole 7.1, 7.2 and 7.3 lines | `^7.4.12` |
| `phpunit/phpunit` | `^11.5.56` | PKSA-z3gr-8qht-p93v affects `>=11.0.0,<11.5.50` — already clear | unchanged |

`symfony/yaml` is the instructive one: `typo3/cms-core` itself requires only `^7.1.4`, so inheriting core's
constraint would have shipped a vulnerable parser. `^7.4.12` is a subset of core's range, so narrowing costs
nothing.

For the `release-13.x` branch the same check gives **`typo3/cms-core: ^13.4.34`** (same advisory,
`>=13.0.0,<13.4.34`) and **`apache-solr-for-typo3/solr: ^13.1.4`** (TYPO3-EXT-SA-2026-025 affects
`>=13.0.0,<13.1.4`). EXT:solr 14.0 has no advisories against it.

The PHPUnit constraint follows a related rule: align with **core's own dev requirement** (`^11.5.56` on
v14.3.7), not with the widest range TF permits (`^11.2.5 || ^12.1.2 || ^13.0.2`). Core, EXT:solr (`^11.5.55`)
and TF all converge on 11.5.x, so advertising 12 and 13 support would claim something the stack cannot
exercise.

The drift canary (§17.4) is the natural place to re-run the advisory check, since a new advisory against an
already-pinned minimum is exactly the kind of thing nobody notices until a release.
- `SiteBasedTestTrait` is **not in typo3/testing-framework** — it is a core test fixture. EXT:solr reaches it via
  `"preferred-install": {"typo3/cms-core": "source"}` plus an `autoload-dev` mapping
  `"TYPO3\\CMS\\Core\\Tests\\": ".Build/vendor/typo3/cms-core/Tests/"`. Every consumer would otherwise have to
  replicate both. Using `sbuerk/typo3-site-based-test-trait` removes that entirely.
- That package's namespace is `SBUERK\TYPO3\Testing\SiteHandling\SiteBasedTestTrait` — a rename, not a drop-in,
  but contained inside our base class so consumers never see it. It also ships its own
  `SBUERK\TYPO3\Testing\TestCase\FunctionalTestCase`, worth evaluating as the base to extend.
- Its published lines are 1.0.x → v12, 2.0.x → v13, 3.0.0 → v14 — **complete coverage of every branch we now
  target**. This was the plan's one hard gap while TYPO3 11 was in scope; dropping 11 closes it outright.

## 10. Future scope, noted not planned

A Solr testing framework usable **without EXT:solr** — driving a bare Solr from TYPO3 functional tests. Most of
§6–§8 (container contract, readiness polling, document fixtures, seeding) is already EXT:solr-independent; §3–§5
is not. Worth keeping the Solr-transport layer free of EXT:solr imports so this stays reachable, but not a goal
for the first releases.

## 11. Open questions

1. Core isolation strategy A vs B (§5) — recommendation is A.
2. Re-test the image with no mount to settle §6.

That is all of them. Closed since the first draft, all by narrowing scope to TYPO3 13 and 14:

- TYPO3 11 and 12 dropped (both EOL), which settled the missing `sbuerk` v11 trait, the EXT:solr 11.5-vs-11.6
  configset question, and the PHP 7.4 syntax constraint.
- Each remaining branch targets exactly one live EXT:solr minor, so one pinned image tag each.
- Both branches share a PHP `^8.2` floor, so there is no PHP divergence.

The remaining two questions are both answerable by the Phase 1 spikes, neither blocks starting, and neither has
a per-branch component.


## 12. Field evidence from a TYPO3 11.5 project

Notes gathered while building Solr coverage for a TYPO3 11.5 project (bistumlimburg.de, EXT:solr 11.6).
They are the raw material for this package: an add-on to `typo3/testing-framework` that gives a functional
test class its own Solr core and a way to load documents into it.

Everything below was verified by running it, not read from documentation. Where a claim comes from a
measurement, the measurement is named.

> **Scope.** These observations come from a TYPO3 11.5 / EXT:solr 11.6 DDEV project — a major line this
> package no longer targets (§2). They are reported as measured, and several are context-specific rather than
> general; §6 reconciles the container observations with what the shipped image actually does.

### 12.1 The problem

`typo3/testing-framework` hands every test **class** its own database schema — the instance identifier is
`substr(sha1(static::class), 0, 7)` and the schema is named after it. Solr has no equivalent. One Solr
serves the whole run, so every class reads and writes the same core.

That makes any assertion about the index a statement about whatever else the suite indexed, and it would
become a statement about whatever runs *beside* it the moment classes execute in parallel. Counting
documents is the obvious casualty; a `deleteByQuery` that is not scoped by `siteHash` is the dangerous one.

### 12.2 A core per test class

Solr's CoreAdmin API can create and drop cores at runtime, from a configset that already exists in the
container. Verified against `typo3solr/ext-solr:11.5`:

```
GET /solr/admin/cores?action=CREATE&name=core_de_<hash>&configSet=ext_solr_11_0_0
    &schema=german/schema.xml&dataDir=/var/solr/data/data/core_de_<hash>
GET /solr/admin/cores?action=UNLOAD&core=core_de_<hash>&deleteDataDir=true&deleteInstanceDir=false
```

Both return `status: 0`, and the core appears in and disappears from `action=STATUS`. Creation takes about
140 ms, which is affordable per test method.

Name the core from the test class (`'core_de_' . substr(sha1(static::class), 0, 10)`) so it matches the
way the framework names the database, and create it in `setUp()` **after** `parent::setUp()` but before
anything reads the site configuration.

The site configuration reaches the core through `%env(SOLR_CORE_DE)%`, so the environment variable has to
be set before the first site lookup:

```php
putenv('SOLR_CORE_DE=' . $core);
$_ENV['SOLR_CORE_DE'] = $core;
```

### 12.3 The part that actually costs time: static state

`FunctionalTestCase::setUp()` calls `GeneralUtility::purgeInstances()`, so singletons do not survive
between test classes. **A `static` property is not a singleton and nothing resets it.** It lives for the
whole PHPUnit process.

Three such caches sit in the path between a test and its Solr core. Two of them break core isolation
outright:

`ApacheSolrForTypo3\Solr\System\Util\SiteUtility::$languages` — `public static`, keyed by root page and
language only. It holds the language configuration, which carries `solr_core_read` with `%env(SOLR_CORE_DE)%`
**already resolved**. A second class using the same root page therefore gets the first class' core name.
This is the one that breaks isolation hardest.

`ApacheSolrForTypo3\Solr\System\Cache\TwoLevelCache::$firstLevelCache` — `protected static`, keyed by
cache name and id. `SiteRepository` caches whole site objects in it under
`SiteRepository_getSiteByPageId_<id>`, so the site — including its connection configuration — outlives the
class that resolved it.

`ApacheSolrForTypo3\Solr\ConnectionManager::$connections` — `protected static`, keyed by
`md5(json_encode($readNode) . json_encode($writeNode))`. Harmless in practice: the hash contains the core,
so a changed core yields a new connection. Worth knowing before suspecting it.

Reset the first two when the core is created:

```php
SiteUtility::$languages = [];
GeneralUtility::makeInstance(TwoLevelCache::class, 'runtime')->flush();
```

`TwoLevelCache::flush()` is the only public way into its static first level — it does
`self::$firstLevelCache[$this->cacheName] = []` alongside flushing the backend.

### How this presents

It does not look like a caching problem. Two observed shapes:

- A *different* test class fails. A command produced six index queue rows where its fixture expected ten,
  with no hint of Solr or caching in the message. The class passed when run alone.
- Solr answers `404 Not Found` on a query. The environment variable held the right core, but the site
  resolved to the previous class' core, which had just been unloaded. Only visible by printing
  `getReadService()->getCorePath()` next to `getenv('SOLR_CORE_DE')`.

The rule that follows: **a test that passes alone and fails in the suite is a state-leak symptom.** Run
the suspected pair together and confirm the order matters before touching any assertion.

### 12.4 siteHash is reproducible

```php
$siteHash = sha1($domain . $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] . 'tx_solr');
```

`FunctionalTestCase` pins `encryptionKey` to `i-am-not-a-secure-encryption-key`, so the hash is
deterministic for a given site base. Verified: the formula reproduces exactly the hash a running functional
test computed for `https://localhost/`.

Do not write the hash into a fixture. Resolve the site instead and ask EXT:solr for it, so nothing repeats
the formula:

```php
$domain = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByRootPageId($rootPageId)->getBase()->getHost();
$siteHash = GeneralUtility::makeInstance(SiteHashService::class)->getSiteHashForDomain($domain);
```

### 12.5 Where the Solr configuration comes from

**EXT:solr ships the whole skeleton itself**, at `Resources/Private/Solr/`: the configsets, a
`core.properties` per language, `solr.xml` and `zoo.cfg`. A DDEV project's `.ddev/solr` is a copy of
exactly that, which is why the DDEV documentation tells you to copy it there.

This package should therefore **not carry its own copy**. Read the skeleton out of the installed
extension. Measured on the project this came from, the difference is not cosmetic:

- `.ddev/solr` carries configset `ext_solr_11_0_0` and 2 cores,
- the installed `apache-solr-for-typo3/solr` 11.6 carries `ext_solr_11_6_0_elts` and 40 cores.

The checked-in DDEV copy had drifted several minor versions behind the extension it is meant to serve, and
nothing noticed because the cores it does define still work. A package that vendored such a copy would
freeze that drift for every consumer, and would additionally carry a GPL configset and a 248 KB binary
plugin jar it does not own.

The consequence for core creation: **do not hardcode the configset name.** `ext_solr_11_0_0` is correct
only for whatever skeleton happens to be mounted. Read it from the `core.properties` of an existing core
in the skeleton — the side that assembles the container has the files on disk and can export the value,
for example as `SOLR_CONFIGSET`, next to `SOLR_HOST` and `SOLR_CORE_DE`.

### 12.6 Document fixtures

`importSolrDataSet()` should be to Solr what `importCSVDataSet()` is to the database. YAML, not JSON —
these files are written and read by hand, and only YAML carries comments, which matters because several
fields are derived and need an explanation next to them.

```yaml
documents:
  -
    rootPageId: 100
    type: news
    uid: 101
    title: 'Hidden news page'
```

A document states `type`, `uid` and `rootPageId`. Everything else is filled in by the importer:

- `appKey` is always `EXT:solr`,
- `site` and `siteHash` come from the resolved site,
- `id` is `<siteHash>/<type>/<uid>`.

**Name the site by its root page, never by a domain.** A project serves many sites; a domain copied into a
fixture is right until someone changes that site's base, and then it is silently wrong.

`siteHash` and `id` must stay overridable. Two cases need it: an event is indexed as one document per
occurrence and carries the occurrence in its id
(`<siteHash>/tx_calendarize_domain_model_event/<uid>/index/<indexUid>`), and a test about an unclaimed
`siteHash` needs a document belonging to no site at all.

**Documents must not have to match a record fixture.** A document whose record does not exist is precisely
what a test about stale index entries needs, and no record fixture can express it.

### 12.7 Seeding instead of indexing

Indexing a page means *rendering* it, which drags the whole site package into the test and is far too slow.
Generate a seed once and load it.

Generate the Solr documents and the record fixture **from one definition**, and write them together: a
document only means something next to the record it claims to describe, because that is what any
index-consistency check looks up. A run over the untouched pair must report nothing stale — that is the
assertion proving the check does not produce false positives.

**Never build a seed by exporting a real installation.** A production index carries editorial text, author
names and venue addresses. Invent the content. For reference, an invented seed of 25 documents covering
four document types came to 20 KB of YAML plus 8 KB of CSV.

### 12.8 runTests.sh integration

The functional suite has to bring its own Solr, or the tests only pass in whatever local environment
happens to have one. What this needed, each point learned by it failing first:

- **Fully qualified image name.** `podman` refuses `typo3solr/ext-solr:11.5` with *"short-name did not
  resolve to an alias"*; use `docker.io/typo3solr/ext-solr:11.5`.
- **The cores come from a mounted skeleton.** Started with nothing mounted, the image comes up with
  **zero cores**. The core definitions live in `core.properties` files (`configSet=ext_solr_11_0_0`,
  `schema=german/schema.xml`, `dataDir=../../data/german`) next to the configsets. In a DDEV project that
  skeleton is already tracked under `.ddev/solr`.
- **Copy the skeleton per run, do not mount it in place.** Mounting the working copy makes a test run
  collide with a Solr the developer already has open on that same directory, and writes into the checkout.
- **Put the index on a `tmpfs`** (`--tmpfs /var/solr/data/data`). Solr writes it as its own container user,
  whose uid the host cannot remove afterwards — cleanup fails with *"Keine Berechtigung"* and needs
  `podman unshare rm -rf`.
- **Waiting for the port is not enough.** A generic `waitFor()` that probes TCP and gives up after eleven
  seconds is wrong twice over: Solr opens the port well before its cores load, and needs far longer than
  eleven seconds to boot at all. Poll `admin/cores?action=STATUS` for the core name instead, with a budget
  around two minutes. The symptom of getting this wrong is a misleading
  `Interface "Psr\Http\Client\ClientExceptionInterface" not found` — a connection exception failing to
  construct.
- **Load the seed after the wait, and make its failure fatal.** A loader whose exit code is ignored will
  quietly leave an empty index.

### Solr's JSON update format

Posting a list of documents as `{"add": [ ... ]}` does not work — the command syntax takes *one* document
per `add` key. Solr reads the array as a single malformed document and answers *"Document is missing
mandatory uniqueKey field: id"*. Post a bare JSON array to `/update` instead, and send `delete` and
`commit` as their own requests.

### 12.9 Verification discipline

Two traps, both hit while writing these tests:

- **A green check proves nothing until it has failed on purpose.** Point a check at the wrong tree and it
  reports success over an empty scan.
- **A vacuous assertion looks like a passing test.** `assertNull($cache->get(...))` held whether or not
  anything cleared the cache, because without `AdditionalConfiguration.php` the cache fell back to a
  `NullBackend` that stores nothing. What exposed it was a *control* test asserting the opposite case —
  that an unrelated run leaves the entry alone. Register a real backend through
  `configurationToUseInTestInstance` and fetch it via `CacheManager::getCache()`; never construct a backend
  by hand, since constructor signatures differ between core majors.

### 12.10 Open for the package

- **Parallelism.** Cores are per class. Under in-process parallelism (paratest) two classes would still
  share a Solr container but each hold its own core, which is fine — the static caches above are the real
  obstacle, since they are per process, not per class.
- **`plugin.tx_solr.index.enableCommits = 0`** is a legitimate production setting (it stops EXT:solr
  committing after every indexed item). A test helper therefore cannot assume a write is visible; commit
  explicitly.
- **Where the trait belongs.** It must be autoloadable by every test that needs it, which in a monorepo
  means a package whose `Tests` namespace is registered in the root `autoload-dev`.



## 13. Shape of the deliverable

A TYPO3 extension / Composer package providing functional-test infrastructure for EXT:solr, in two halves:

- **PHP** — a `SolrFunctionalTestCase` extending `typo3/testing-framework`, giving each test class an isolated
  Solr core, resetting EXT:solr's leaking statics, and loading document fixtures.
- **Tooling** — `Build/Scripts/runTests.sh` building blocks that bring a Solr container up on the test network,
  so a consumer's functional suite is self-contained.

Packagist name is **`calien/typo3-solr-testing`** — note the vendor is `calien`, without the `666` that the
GitHub organisation carries. The vendor path is therefore `vendor/calien/typo3-solr-testing/`.

### Branching and versioning

One branch per TYPO3 core major, names and tags following the **core** version:

| Branch | branch-alias | TYPO3 | EXT:solr | TF | `sbuerk/…-trait` | PHP |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `release-13.x` | `13.x-dev` | 13.4 | 13.1 | ^8 \|\| ^9 | ^2.0 | ^8.2 |
| `main` | `14.x-dev` | 14.3 | 14.0 | ^9 | ^3.0 | ^8.2 |

**Two branches, and that is the whole matrix.** TYPO3 11 and 12 are both EOL; EXT:solr 11.6 is ELTS outside the
public repository, and EXT:solr declared 12 dead in the security announcement for that line. Tags are `13.0.0`,
`14.0.0`, … `main` is renamed to `release-14.x` when TYPO3 15 opens.

**Develop `main` (TYPO3 14) first, then back-port to `release-13.x`.** It has the smallest gap to upstream —
EXT:solr 14 already ships the three reset APIs and the paratest sharding, so the base class there is closest to
what we want and the back-port direction is "re-implement what upstream added", which is well understood.

The narrowed scope removes a lot of planned work: no PHP 7.4 syntax constraint, no missing `sbuerk` trait, no
TSFE-faking indexing strategy, no PHP-version divergence (both branches are `^8.2`), and one pinned image tag per
branch instead of two candidate EXT:solr minors. What remains genuinely per-branch is the indexing strategy and
the static-reset emulation — two focused differences.

Every branch is standalone. No shared trunk, no `||` spanning core majors inside a branch, no runtime version
branching. This is the `typo3-core-version-compatibility` rule applied at the repository level.

## 14. Open decisions to close before coding

### 14.1 Core isolation strategy — recommend A

Strategy A (core per test class via CoreAdmin `CREATE`/`UNLOAD`) over B (pre-baked cores sharded per paratest
worker). A is per-class, mirrors how TF isolates the database, and measured at ~140 ms. Derive the core name from
`static::getInstanceIdentifier()` rather than re-implementing `substr(sha1(static::class), 0, 10)`, so it tracks
whatever TF does; append the paratest `TEST_TOKEN` suffix only when that variable is set, so A composes with B's
image rather than fighting it.

**Needs a spike** (§15.1) — everything downstream depends on CoreAdmin being reliable enough to do per class.

### 14.2 Re-test the image with no mount

One `docker run` of `docker.io/typo3solr/ext-solr:11.6` with no volume and no `TYPO3_SOLR_ENABLED_CORES`, then
`GET /solr/admin/cores?action=STATUS`. If 40 cores appear, the "copy the skeleton per run" step and its
permission problems are out of scope for this package. Cheap, and it removes or confirms a whole section of the
tooling.

### 14.3 Closed by narrowing scope

Three decisions that were open in the first draft no longer exist, all resolved by dropping TYPO3 11 and 12:

- the missing `sbuerk/typo3-site-based-test-trait` v11 line (2.0.x and 3.0.0 cover both remaining branches);
- whether a v11 branch targets EXT:solr 11.5 as well as 11.6 (differing configsets);
- the PHP 7.4 syntax constraint, which would have barred constructor promotion, enums and `readonly` from shared
  code.

Only 1.1 and 1.2 remain, and both are answered by the Phase 1 spikes rather than by a judgement call.

## 15. Phase 1 — spikes (`main` / TYPO3 14)

Throwaway code, no packaging, answers the questions that would otherwise be designed around.

1. **CoreAdmin per class.** Create and unload a core against `docker.io/typo3solr/ext-solr:14.0`, from a real
   functional test. Measure creation cost, confirm `UNLOAD` with `deleteDataDir=true` leaves nothing behind, and
   check behaviour when a previous run crashed and left a core loaded.
2. **Configset discovery.** `GET /solr/admin/configs?action=LIST` against the running container; confirm it
   returns `ext_solr_14_0_0` and works without any mount.
3. **Bare image core count** (§14.2).
4. **Readiness poll.** Time a cold start to the first successful `admin/cores?action=STATUS` hit for a named
   core, to set the real budget rather than guessing at two minutes.
5. **Configset provisioning in user-managed mode** (§16.6). The image runs `solr-foreground --user-managed`, so
   check whether the ConfigSets API can `UPLOAD` or `CREATE` a configset at runtime there at all. If it cannot,
   a custom schema can only be provisioned before Solr starts and §17 has to carry it.
6. **Log watcher availability** (§16.9). `solr.xml` declares no explicit watcher — confirm
   `admin/info/logging?since=<ms>` still returns events, and find where its buffer starts dropping them under
   an import large enough to matter.

Output: a short note appended to §11 closing each question. No production code yet.

## 16. Phase 2 — the PHP package on `main`

### 16.1 Base test case

`SolrFunctionalTestCase extends FunctionalTestCase` (or sbuerk's `FunctionalTestCase` — decide in 3.0), using
`SBUERK\TYPO3\Testing\SiteHandling\SiteBasedTestTrait`.

Ports the stable two-thirds identified in §3:
`assertSolrIsEmpty`, `assertSolrContainsDocumentCount`, `cleanUpSolrServerAndAssertEmpty`,
`cleanUpAllCoresOnSolrServerAndAssertEmpty`, `waitToBeVisibleInSolr`, `validateTestCoreName`,
`getSolrConnectionInfo`, `getSolrConnectionUriAuthority`, `writeDefaultSolrTestSiteConfiguration(ForHostAndPort)`,
`failWhenSolrDeprecationIsCreated`, `addTypoScriptConstantsToTemplateRecord`,
`addSimpleFrontendRenderingToTypoScriptRendering`, `addPageToIndexQueue`, `getIndexQueueItem`,
`processEventQueue`, `indexPages`.

Excluded deliberately: EXT:solr's `inject()` and `callInaccessibleMethod()` reflection helpers. They are Nimut
leftovers, unrelated to Solr, and belong in a consumer's own base class if wanted.

### 16.2 Static-state reset

In `tearDown()`, per §4. On `main`:

```php
ConnectionManager::resetConnections();
SiteUtility::reset();
TwoLevelCache::flushAllCaches();
```

Back-ported branches emulate whichever of the three does not exist yet. This is the highest-value behaviour in
the package and must be covered by a test that **fails without it** — two test classes sharing a root page, the
second asserting it sees its own core. A green assertion here proves nothing until it has failed on purpose.

### 16.3 Core lifecycle

`SolrCoreManager` — create in `setUp()` after `parent::setUp()` and before the first site lookup, unload in
`tearDown()`. Configset discovered at runtime via the configs API, never hardcoded, never read from a mount.
Keep this class free of EXT:solr imports (§10).

Site configuration gets the core name written directly through `writeDefaultSolrTestSiteConfigurationForHostAndPort`,
rather than through `%env(SOLR_CORE_DE)%`. The env-var indirection in §12 is right for a project whose
site config already uses placeholders; a package writing its own site configuration does not need it. Support the
env path as an opt-in for projects that do.

### 16.4 Document fixtures — YAML, and why not CSV

**Decision: YAML for Solr documents. CSV stays for the DB records.** The package uses both, each where it fits
— TF's own `importCSVDataSet()` for the record side, ours for the document side. This is not one format winning.

The "CSV is loadable by default, YAML needs a parser" trade-off does not exist in TYPO3:
**`symfony/yaml ^7.1.4` is a hard `require` of `typo3/cms-core`** on v13.4 and v14.3 alike — core parses site
configuration and `Services.yaml` with it. There is no dependency to add and nothing to bundle.

With cost off the table it comes down to shape, and Solr documents are not tabular:

- **Multi-valued fields are normal** (`keywords`, the `*_stringM` / `*_textM` dynamic fields). TF's CSV has no
  in-cell array convention and we would have to invent a separator. Its JSON handling is not a
  counter-example — that works only because the *database column* is a Doctrine `JsonType` that
  `bulkInsert()` encodes, which is a DB-specific special case, not a general array syntax.
- **Documents are sparse and heterogeneous.** A `pages` document and a `tx_news` document share few fields.
  CSV's rectangle forces a header block per type and a wide field of empty cells.
- **Comments.** §12 wants them because several fields are derived and need explaining next to the
  value. TF's CSV only supports a `#` in the first cell — whole-line, never inline.

Format per §12: `appKey`, `site`, `siteHash` and `id` derived; `siteHash` and `id` overridable;
documents allowed to exist with no matching record. Post a bare JSON array to `/update`, with `delete` and
`commit` as separate requests. Resolve `siteHash` through `SiteHashService`, never by repeating the formula.

Plus a seed generator producing the Solr documents and the record CSV **from one definition**, since a document
only means something next to the record it claims to describe.

### 16.5 Defaults and hard validation on import

`importCSVDataSet()` is strict, and it is worth being precise about *where* its strictness comes from, because
the Solr version has to source the same authority from somewhere else.

`DataSet::import()` calls `read($path, applyDefaultValues: true, checkForDuplicates: true)`, and then:

- **defaults come from TCA** — `applyDefaultValues()` fills any field missing from the fixture with
  `$GLOBALS['TCA'][$table]['columns'][$field]['config']['default']`. Its docblock gives the reason: it is
  *"basically required for running the functional tests in a SQL strict mode environment"*;
- **hard failure comes from the database schema** — `import()` looks every fixture column up in
  `listTableColumnInfos($tableName)` to build its `$types` map, so a column that does not exist blows up, and
  strict mode rejects bad values on `bulkInsert()`.

So TF validates against TCA and the DBMS. **We validate against Solr's Schema API**, which is the equivalent
authority and is already reachable from the container the test is talking to:

```
GET /solr/<core>/schema/fields          -> explicit fields, with type, multiValued, required, default
GET /solr/<core>/schema/dynamicfields   -> the *_stringS / *_textM / … patterns
GET /solr/<core>/schema/uniquekey       -> confirms `id`
```

Fetch once per core and cache for the run.

**Defaults**, in two layers:

1. EXT:solr's derived fields, per §12 — `appKey` is always `EXT:solr`, `site` and `siteHash` come from
   the resolved site, `id` is `<siteHash>/<type>/<uid>`. These stay overridable.
2. A schema field's own `default` attribute, which is the direct analogue of TCA's `config.default`.

**Hard failures at import time.** Every one of these is something Solr either reports unhelpfully or does not
report at all, which is exactly why the check belongs in the importer:

| Condition | Why import must fail |
| :--- | :--- |
| Missing `type`, `uid` or `rootPageId` | the derived `id` cannot be built at all |
| Missing a schema-`required` field, or `uniqueKey` | Solr rejects at index time, pointing at Solr, not at the fixture |
| **Unknown field** — matches no explicit field and no dynamic pattern | the common typo case |
| **Array given for a `multiValued=false` field** | Solr errors, unhelpfully |
| **Scalar given for a `multiValued=true` field** | *Solr accepts it silently and wraps it* — fixture and index then disagree with no error anywhere |
| Type mismatch against `pint` / `pdate` / `boolean` | Solr errors, unhelpfully |
| Duplicate document `id` | *Solr silently overwrites*, so the fixture indexes fewer documents than it lists — see §16.9 |

The two italicised rows are the ones that justify the whole exercise: they are silent today. The rest is about
moving an error from "Solr said no" to "line N of this fixture file said no".

The duplicate check runs on the **resolved** `id`, after derivation — not on what the YAML literally says. Two
documents can each look distinct while deriving the same `<siteHash>/<type>/<uid>`, and that is the case most
likely to be written by accident.

**One honest limit.** Dynamic fields mean unknown-field detection is not typo-proof: `titel_stringS` matches
`*_stringS` and will be accepted. We catch fields matching *nothing*, not fields matching the wrong thing. Say
so in the docs rather than letting people over-trust it.

Errors follow `DataSet`'s shape — name the file and the offending key, e.g. TF's
`'DataSet "%s" containes a duplicate record for idField "%s.uid" => %s'` and its missing-file throw
(`'File "%s" does not exist'`, code `1476049619`). Unique exception codes throughout, per `code-quality`.

Validation is strict by default. A deliberately-malformed document is still expressible — the tests §12
wants, a document with an unclaimed `siteHash` or one whose record does not exist, are *value* cases and stay
schema-valid. Only a test of EXT:solr's own bad-field handling would need an escape hatch, so add one when such
a test actually appears, not before.

### 16.6 Custom and external schemas

Nothing in §16.5 may assume EXT:solr's shipped schema. Consumers are free to supply their own configset, and it
need not resemble the shipped one at all. Reading the live Schema API already respects that — but several
neighbouring assumptions do not, and they have to be configuration with defaults rather than constants:

| Assumption | Must become |
| :--- | :--- |
| configset `ext_solr_14_0_0` | configurable; discovery via `admin/configs?action=LIST` is the *default*, not a requirement |
| cores `core_en` / `core_de` / `core_da`, dirs `english` / `german` / `danish` | configurable; these are image conventions, not Solr facts |
| `uniqueKey` is `id` | read from `schema/uniquekey`, never assumed |
| the pinned `typo3solr/ext-solr` image | overridable — a consumer with a custom schema runs their own container |

Two facts from the shipped configset shape the caching:

- **The schema is per language, not per configset.** `ext_solr_14_0_0` contains `conf/arabic/schema.xml`,
  `conf/danish/schema.xml` and so on, selected per core through `schema=<lang>/schema.xml` in
  `core.properties`. Field sets can therefore differ between cores on one server, so **cache the schema keyed
  by core**, never once per run.
- **The shipped configset is `ClassicIndexSchemaFactory`** (verified in `solrconfig.xml`; the
  `ManagedIndexSchemaFactory` block sits commented out next to it), so its schema cannot change at runtime and
  caching is safe. A custom setup may enable `ManagedIndexSchemaFactory` with `mutable="true"`, so expose an
  explicit cache invalidation for a test that alters the schema mid-run.

**The trap where §16.5's two halves collide.** Defaults and validation can fight each other on a foreign schema:
`appKey`, `site`, `siteHash` and `id` are **EXT:solr document conventions, not schema facts**. Deriving
`appKey` into a document whose schema has no such field would inject a field our own validator then rejects —
a self-inflicted failure on a perfectly valid setup. So **derive only fields the live schema actually knows**,
and treat the EXT:solr convention as the default case rather than the only one.

This is also the concrete reason §10 asks the Solr transport layer to stay free of EXT:solr
imports: a schema-driven importer and assertion are most of what a consumer without EXT:solr would need.

**Overriding the schema is a feature, not just a tolerated case.** Projects extend EXT:solr's schema routinely
— extra fields for their own record types — so supplying a schema has to be supported directly, at two levels:

1. **Selection**, in PHP: `configSet` and `schema` are passed to CoreAdmin `CREATE` (`configSet=<name>`,
   `schema=<lang>/schema.xml`), so a test can pick any configset already present on the server. Both settable
   per test class, defaulting to discovery.
2. **Provisioning**, in `solr.sh` (§17): a consumer-supplied configset directory is placed into
   `/var/solr/data/configsets/<name>` before Solr starts, so step 1 has something to select.

Step 2 exists because **the image runs Solr in user-managed (standalone) mode** — `CMD ["solr-foreground",
"--user-managed"]` — not SolrCloud. Whether the ConfigSets API can `UPLOAD` or `CREATE` a configset at runtime
in that mode is version-dependent and **must be verified in the spike (§15.5)** rather than assumed; if it
cannot, provisioning before start is the only route and §17 carries the whole weight.

Note this partly reinstates the skeleton mount §12 wrestled with — but now for a real reason, a custom
configset, rather than to supply cores the image already has. Same mechanics, different justification: copy per
run, never mount the working copy in place.

### 16.7 `assertSolrDataSet()` — port the reporting, not the format

Worth doing, and the valuable part of `assertCSVDataSet()` is not the CSV. It is the failure reporting, which
maps onto Solr almost one-to-one:

| `assertCSVDataSet()` | Solr counterpart |
| :--- | :--- |
| `Record "<table>:<uid>" not found in database` | `Document "<id>" not found in index` |
| `Assertion in data-set failed for "<id>":` + field diff | same, over document fields |
| `Not asserted record found for "<id>":` | **unexpected document in the index** |

Four behaviours to carry over verbatim:

1. **Accumulate every failure, then `self::fail()` once** with the whole list. One run tells you everything
   that is wrong, not just the first thing.
2. **`renderRecords()`-style field-by-field diff** for a document that exists but differs — the reason
   `assertCSVDataSet()` failures are readable at all.
3. **Strict-set semantics** via the unset-as-you-match bookkeeping, so leftovers are reported. For Solr this is
   the most valuable of the three: it is what catches *over*-indexing, which no positive assertion can see.
4. **Bump the assertion counter on an empty expectation**, so asserting an empty index does not trip
   "test did not perform any assertions".

Assert only the fields the fixture lists, exactly as `assertCSVDataSet()` asserts only the header columns. A
Solr document carries dozens of fields — scoring, `_version_`, timestamps — and demanding all of them would
make every assertion unmaintainable.

### 16.8 Writing and emptying

`<delete><query>*:*</query></delete>` followed by an explicit commit, which is what EXT:solr's base already
does. Two things not to assume:

- **Commit explicitly, always.** `plugin.tx_solr.index.enableCommits = 0` is a legitimate production setting,
  so a helper cannot assume a write is visible (§12).
- Per-class cores (§14.1) make a *class*-level empty redundant, but **between test methods it is still needed** —
  one core serves every method in the class.

### 16.9 Post-import verification and error surfacing

Parse-time checks only catch what we thought to look for. The backstop is a **count check after import**, and
it is the most valuable single check in the importer because it catches silent loss of *any* cause:

1. Empty the core, import, then **commit explicitly** (§16.8 — `enableCommits = 0` is legitimate).
2. Query the core and compare `numFound` against the number of documents the fixture declared.
3. On mismatch, **diagnose rather than just report a number**:
   - group the fixture's documents by **resolved** `id` and report any collisions first — the most likely
     cause, and the one Solr will never tell us about;
   - if no collisions, surface Solr's own errors (below) alongside the expected-vs-actual count.

Counting must be scoped to a known-empty core, or taken as a before/after delta. Comparing against a core with
leftovers reports a discrepancy that is not the fixture's fault.

**Surfacing Solr's errors**, in order of reliability:

1. **The update response itself.** A failed `/update` returns a non-2xx with a message. This is the primary
   source and is always available — read it before anything cleverer.
2. **`GET /solr/<core>/admin/info/logging?since=<ms>`** for errors that never reach the response. Capture a
   timestamp before the import and fetch WARN/ERROR events since then. Two caveats to settle in the spike
   (§15.6): `solr.xml` in the shipped configset declares **no explicit log watcher**, so this depends on Solr's
   default being active, and the default buffer is small enough to overflow during a large import.
3. **Container logs** as the last resort — `${CONTAINER_BIN} logs solr-func-${SUFFIX}`. Worth wiring into CI as
   an artifact on failure regardless; EXT:solr's own workflow already uploads Solr container logs that way.

**Talk to Solr over plain HTTP, not through Solarium.** Three reasons, and the third is the decisive one:

- **Circularity.** Solarium's client is built from the site configuration, which is exactly what these tests
  are verifying. A check that fails because the thing it verifies is broken cannot diagnose it.
- **Fail-safety.** When the connection is wrong we want Solr's status and body, not a wrapped client
  exception. §12 hit precisely this — a connection exception that could not even be constructed
  (`Interface "Psr\Http\Client\ClientExceptionInterface" not found`), which told nobody anything.
- **Solarium's major differs per branch** — `6.4.1` on EXT:solr 13.1, `7.0.0` on 14.0. Building the framework
  on it would add a whole API surface to the per-branch difference list in §18, for no gain. Raw HTTP has no
  such divergence.

This follows upstream rather than departing from it: EXT:solr's own `IntegrationTestBase` already uses core's
`RequestFactory` and `file_get_contents()` against `/update?commit=true` and `/select?q=*:*` directly, never
Solarium. Use `RequestFactory` for the same reason — it is core API, present on both branches.

## 17. Phase 3 — shipping the `runTests.sh` integration

This is the hardest design problem in the package, so it gets treated as such. `runTests.sh` is a monolithic bash
script that every extension copies from core and re-syncs by hand. Whatever we ship has to survive the consumer
re-syncing theirs.

### 17.1 Do not ship a whole `runTests.sh`

A complete script shipped by this package would be a snapshot of core's at the moment we cut it. It goes stale the
first time core changes theirs, it overwrites whatever suites the consumer added, and it forces us to re-vendor
core's script on every branch forever. Rejected.

### 17.2 Ship a sourceable fragment — recommended

Ship `Build/Scripts/solr.sh` inside the package. The consumer's own `runTests.sh` gains three lines:

```bash
SOLR_LIB=".Build/vendor/calien/typo3-solr-testing/Build/Scripts/solr.sh"
[ -f "${SOLR_LIB}" ] && . "${SOLR_LIB}"
```

and, inside the `functional` case, next to where core already starts redis and memcached:

```bash
solrStart && solrWaitFor || exit 1
```

The consumer's `runTests.sh` stays theirs and stays re-syncable against core. The Solr logic updates with
`composer update`, like any other dev dependency. The guard keeps `-s composer` / `-s composerUpdate` working
before `vendor/` exists — those suites never need Solr, and the `functional` suite by definition has vendor
present.

### 17.3 The coupling contract is five symbols

Verified against core `main`. `solr.sh` consumes exactly:

| Symbol | Set at | Used for |
| :--- | :--- | :--- |
| `CONTAINER_BIN` | core, line ~160 | `podman` or `docker` |
| `CI_PARAMS` | core, line 883 | CI-only container flags |
| `SUFFIX` | core, line 885 | unique per run |
| `NETWORK` | core, line 889 (`typo3-core-${SUFFIX}`) | the test network |
| `CONTAINER_COMMON_PARAMS` | core, line ~191 | appended with our `-e` vars |

That is the same contract core's own redis and memcached services use, unchanged for years — the lowest-risk
seam available. Two consequences worth having in writing:

- **Teardown is free.** `cleanUp()` kills every container attached to `${NETWORK}`, and it is both trapped on
  SIGINT and called at exit. A Solr container started with `--network ${NETWORK}` needs no cleanup code of ours.
- **We never edit core's functions**, only read its variables and append to one of them.

### 17.4 Keeping it current — a drift canary

The risk that remains is core renaming one of those five symbols. Catch it mechanically rather than by noticing a
broken build: a scheduled workflow (`.github/workflows/upstream-drift.yml`) fetches
`Build/Scripts/runTests.sh` from the core branch this branch targets, asserts each of the five symbols is still
assigned, and asserts `cleanUp()` still filters by `--filter network=`. On drift it opens an issue.

Same job also checks the EXT:solr side: that the `typo3solr/ext-solr` tag we pin still exists, and that the
configset name in the target EXT:solr branch's `composer.json` (`extra.TYPO3-Solr.version-matrix.configset`)
has not moved. Both are cheap HTTP calls and both have already bitten someone — the configset name is
version-stamped and drifted three ways in the project §12 came from.

### 17.5 Fallback for consumers who want one file

For anyone unwilling to source from `vendor/`, ship the same fragment wrapped in sentinel comments plus a
`composer typo3-solr-testing:sync-runtests` script that replaces the block between the sentinels in their
`runTests.sh`. Same content, generated in place instead of sourced. Secondary path — build it only if asked for.

### 17.6 What `solr.sh` actually does

- `solrStart` — `${CONTAINER_BIN} run --rm ${CI_PARAMS} --name solr-func-${SUFFIX} --network ${NETWORK} -d`
  `--tmpfs /var/solr/data/data` from a branch-pinned **fully qualified** `docker.io/typo3solr/ext-solr:<tag>`
  (podman rejects the short name), then appends
  `-e TESTING_SOLR_SCHEME=http -e TESTING_SOLR_HOST=solr-func-${SUFFIX} -e TESTING_SOLR_PORT=8983` to
  `CONTAINER_COMMON_PARAMS`.
- `solrWaitFor` — **not** core's `waitFor()`. That is a TCP probe with an ~11 s budget; Solr binds 8983 long
  before its cores load. Polls `admin/cores?action=STATUS` for the named core, budget from spike 2.4.
- `solrSeed` — optional fixture load, non-zero exit is fatal.
- `solrSeed` — optional fixture load, non-zero exit is fatal.

#### Two Solr distributions, selected like the DBMS is

The runner offers **both** Solr images, as two values of one option, exactly as `-d mariadb` and `-d mysql`
are two values that speak the same protocol but are not the same product:

| Option | Image | Ships |
| :--- | :--- | :--- |
| `-S ext-solr` (default) | `docker.io/typo3solr/ext-solr:<ext-solr version>` | EXT:solr's configsets and all 40 language cores baked into `/var/solr/data`, `disable-cores.sh`, a healthcheck, started `--user-managed` |
| `-S apache` | `docker.io/apache/solr:<solr version>` | a bare Apache Solr — no TYPO3 configset, no cores, none of the entrypoint scripts |

A second option carries the version, the way `-i` carries the DBMS version. Its accepted values differ per
distribution, and that is the point rather than an inconvenience: `ext-solr` tags track **EXT:solr**
versions (`14.0`, `13.1`), `apache` tags track **Apache Solr** versions (`10.0.0`, `9.10.1`). Per §17.3 the
accepted set mirrors what the branch can actually use, so the `ext-solr` tag has to match the installed
EXT:solr minor — the configset name is version-stamped (§6). `SOLR_IMAGE_TAG` stays as the env-level escape
hatch.

**The default stays `-S ext-solr`, pinned to the version matching the EXT:solr in use.** That is the
supported combination and what a plain `runTests.sh -s functional` must exercise.

Why carry the official image alongside it:

- **Production usually runs a newer Apache Solr than EXT:solr ships an image for.** Projects here run the
  required specs but a newer server, so the cores in production are not the cores in the shipped image. The
  `apache` lane is what makes that combination testable at all — without it we only ever prove the version
  pairing nobody actually deploys.
- a project with a **custom schema** (§16.6) does not want EXT:solr's baked configsets in the way;
- environments that will not pull a third-party image.

**This is what settles §5 in favour of strategy A.** Per-worker core sharding (B) depends on
`tests_copy-cores-for-paratest.sh`, an entrypoint script that exists only in the EXT:solr image, so it
cannot work against `apache/solr` at all. Creating cores per test class through the CoreAdmin API works
against both, because it only uses Solr's own API. Supporting the official image therefore requires A, and
A costs nothing on the EXT:solr image.

##### ⚠ The official image needs more than a configset — EXT:solr ships a Java plugin

**Do not plan the `apache` path as "the same thing minus the baked cores".** EXT:solr's `solrconfig.xml`
registers a query parser from its own compiled plugin:

```xml
class="org.typo3.solr.search.AccessFilterQParserPlugin"
```

which lives in `Resources/Private/Solr/typo3lib/solr-typo3-plugin-7.0.0.jar` (344 KB, versioned separately
from both EXT:solr and Apache Solr) and is reachable only because EXT:solr's own `solr.xml` declares:

```xml
<str name="modules">analysis-extras,langid,language-models,scripting,clustering,extraction,${solr.modules:}</str>
<str name="sharedLib">typo3lib/</str>
```

A plain `apache/solr` container has neither file. Creating a core from EXT:solr's configset there fails
outright on the unresolvable class — and this is not decoration: that parser is EXT:solr's access-restriction
filter, so any test touching content access groups depends on it.

So `-S apache` has to provision the **whole** `Resources/Private/Solr/` tree out of the installed EXT:solr
package — `solr.xml`, `typo3lib/*.jar` and the configset — not just the configset, and start the container
`--user-managed` to match. Three version couplings meet in that combination (Apache Solr × the plugin jar ×
the configset), and it is a combination **nobody upstream tests**, which is exactly why it needs its own
lane (§17.7) rather than being assumed equivalent.

The `${solr.modules:}` placeholder is the one deliberate seam: a custom schema needing extra modules appends
them through that variable instead of editing `solr.xml`.

Two further consequences for `solr.sh`:

- **Provisioning differs.** With `ext-solr`, cores, plugin and configset are already there and
  `TYPO3_SOLR_ENABLED_CORES` selects among them. With `apache`, `solr.sh` provisions all of it before start
  (§16.6) — there is nothing to select otherwise.
- **Readiness differs.** Against `ext-solr` the poll can wait for a named baked core. Against `apache`
  there are no cores until we create them, so the poll has to wait for the *server*, and the first core
  creation becomes the real proof it is up — including the proof that the plugin loaded. `solrWaitFor` needs
  both paths.

### 17.7 CI

Per `typo3-ci-tooling`: `runs-on: ubuntu-22.04`, podman as default engine, no `-b docker`; one workflow per
branch; functional matrix over sqlite/mariadb/mysql/postgres × oldest and newest supported PHP.

**The Solr distribution is a real matrix axis, not a free variant.** `-S ext-solr` and `-S apache` differ in
how the server is provisioned, whether the TYPO3 plugin is present at all, and how readiness is detected, so
a green `ext-solr` lane says nothing about the other. Both need to run, and the `apache` lane is the one that
breaks when EXT:solr bumps its plugin jar or Apache Solr changes module loading — neither of which shows up
in this repository's own diffs.

It does not need to be a full cross-product: run the DBMS spread on `-S ext-solr` (the default consumers get)
and add a single `-S apache` lane on one DBMS and one PHP version. That covers the provisioning divergence
without doubling the matrix.

## 18. Phase 4 — back-port to `release-13.x`

One back-port, cut from `main` once it is green, with its own CI. Per-branch work is confined to four things:

- **the indexing strategy** (§3) — `executePageIndexer` + `indexPageQueueItem` over the TF frontend
  sub-request, in place of 14's `IndexingService` pipeline. This is the substantial one;
- **the static-reset emulation** (§16.2) — `SiteUtility::reset()` exists on 13.1, but
  `TwoLevelCache::flushAllCaches()` and `ConnectionManager::resetConnections()` do not;
- **no paratest core sharding** — 13.1 has none upstream, and strategy A (§14.1) does not need it;
- the pinned image tag (`13.1`) and the TF `^8 || ^9` / PHPUnit constraints.

Everything else — the core lifecycle, fixtures, seeding, `solr.sh`, CI shape — is shared verbatim.

## 19. Test strategy

This package is a testing tool, so its own suite is the only evidence it works. Both Unit and Functional, both
written by us.

### 19.1 How the testing-framework tests itself

Verified on TF `8`, `9` and `main` — all three are identical in shape:

- **Unit tests only.** `Tests/Unit/…` and a single `Build/phpunit/UnitTests.xml`. There is no functional
  self-test suite on any branch. So TF gives us the unit pattern, and nothing to copy for functional.
- Style: `final class …Test extends UnitTestCase` (or plain `TestCase` where no TYPO3 bootstrap is needed),
  PHPUnit **attributes** — `#[Test]`, `#[DataProvider]` — never `@test` annotations, `declare(strict_types=1)`.
- Fixtures are directories of real-but-minimal packages under `Tests/Unit/…/Fixtures/`, each with its own
  `composer.json` / `ext_emconf.php`, loaded by path.

For the functional side, TF instead **ships boilerplate to copy**: `Resources/Core/Build/FunctionalTests.xml`
and `FunctionalTestsBootstrap.php`, whose header explicitly says extensions should copy rather than reference
them. We copy both to `Build/phpunit/`, per the `code-quality` baseline.

### 19.2 Shipped code must never reach into `Tests/`

`.gitattributes` gets `export-ignore` on `/Tests/`, `/Build/`, `/.github/` — TF's own file is the template.
Anything under those paths is **absent from a dist install**, so shipped `Classes/` referencing them would fail
only for consumers, never for us. CI must catch that rather than trusting review.

TF solves this exact problem and its solution is the one to copy: its two fixture extensions live in
`Resources/Core/Functional/Extensions/{json_response,private_container}/`, are registered in **`autoload`, not
`autoload-dev`**, and are linked into the test instance by `Testbase::linkFrameworkExtensionsToInstance()`
(public on both TF 9 and `main`). `Resources/` is not export-ignored, so they ship.

So fixture extensions split in two, by audience:

| | Lives in | Autoload | Ships | Wired by |
| :--- | :--- | :--- | :--- | :--- |
| Needed by **consumers'** tests — Solr test site settings and configuration | `Resources/Functional/Extensions/<name>/` | `autoload` | yes | `linkFrameworkExtensionsToInstance()` |
| Needed only by **our own** suite | `Tests/Fixtures/Extensions/<name>/` | via plugin | no | `sbuerk/fixture-packages` |

**This split is not optional, and it is the one thing to get right before writing fixtures.**
`sbuerk/fixture-packages` adopts fixture package autoload into the **root package's `autoload-dev`**. A
dependency's `autoload-dev` is never applied, so anything it wires up exists only while *our* repository is the
root. Its README says so directly — the plugin "should not be installed as dependency, special for packages
which are libraries, bundles, extensions, plugins". If `writeDefaultSolrTestSiteConfiguration()` ends up
depending on a fixture extension the plugin provides, it will work in our CI and break in every consumer.

A guard test enforces it: grep shipped `Classes/` for references to the export-ignored paths and fail on a hit.

### 19.3 `sbuerk/fixture-packages` for our own suite

`composer require --dev sbuerk/fixture-packages` plus
`composer config allow-plugins.sbuerk/fixture-packages true`, paths declared under
`extra."sbuerk/fixture-packages".paths`, and in `Build/phpunit/FunctionalTestsBootstrap.php`:

```php
if (class_exists(\SBUERK\AvailableFixturePackages::class)) {
    (new \SBUERK\AvailableFixturePackages())->adoptFixtureExtensions();
}
```

That registers them with TF's `ComposerPackageManager`, so `$testExtensionsToLoad` can name them by extension
key or composer package name. Current release is 1.1.3; the README still carries an EXPERIMENTAL banner from
pre-1.0, which looks stale but is worth a glance at the changelog before pinning.

### 19.4 What the tests must actually prove

Unit tests cover the pieces with no Solr dependency — fixture parsing, document derivation (`id`, `siteHash`,
`appKey`), core-name resolution, configset selection.

Functional tests are the real evidence, and they must prove the suite itself is sound, not merely that Solr
answers:

1. **Basic Solr functionality end to end** — a core is created, documents are written, a query returns them,
   the core is unloaded and leaves nothing behind.
2. **Isolation actually isolates.** Two test classes sharing a root page, the second asserting it sees its own
   core and not the first's. Per §4 this is the package's highest-value behaviour, and it is
   invisible until it breaks something unrelated.
3. **`importSolrDataSet()` round-trips** — documents land with derived fields correct, overrides honoured, and a
   document with no matching record is accepted.
4. **Negative controls.** Per §12, a green check proves nothing until it has failed on purpose. Every
   assertion above needs its inverse: the isolation test must fail with the static reset removed, and the empty
   index must be provably distinguishable from a broken connection.

Point 4 is the discipline that makes points 1–3 worth anything, and is a review gate, not a nice-to-have.

## 20. Documentation

README plus `Documentation/` per `typo3-documentation`. The parts that will actually save a consumer time are the
container contract (§6), the core/directory naming mismatch (`english` vs `core_en`), and the
static-leak symptom — *a test that passes alone and fails in the suite*.

## 21. Sequencing summary

| Phase | Scope | Gate |
| :--- | :--- | :--- |
| 1 | Spikes on `main` | §14.1, §14.2, §16.3 answered |
| 2 | PHP package on `main` | Leak test fails without the reset |
| 3 | `runTests.sh` + CI on `main` | Full matrix green per `typo3-test-matrix` |
| 4 | Back-port to `release-13.x` | Branch green on its own matrix |
| 5 | Docs, first tags | — |

Phase 1 is small and removes most of the design risk. Nothing before it needs a decision from §1.

