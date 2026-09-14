<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Calien\SolrTesting;

use ApacheSolrForTypo3\Solr\ConnectionManager;
use ApacheSolrForTypo3\Solr\Domain\Search\Query\Helper\EscapeService;
use ApacheSolrForTypo3\Solr\Domain\Site\SiteHashService;
use ApacheSolrForTypo3\Solr\System\Cache\TwoLevelCache;
use ApacheSolrForTypo3\Solr\System\Util\SiteUtility;
use Calien\SolrTesting\DataSet\SolrDataSet;
use Calien\SolrTesting\Exception\InvalidSolrDataSetException;
use Calien\SolrTesting\Solr\SolrSchema;
use Calien\SolrTesting\Solr\SolrServer;
use Calien\SolrTesting\Solr\SolrSkeleton;
use SBUERK\TYPO3\Testing\SiteHandling\SiteBasedTestTrait;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Base class for a functional test that queries Solr.
 *
 * Extend it instead of the testing framework's FunctionalTestCase. The test class
 * then has a Solr core of its own, created before the test and dropped after it,
 * so two classes never read each other's documents.
 */
abstract class SolrFunctionalTestCase extends FunctionalTestCase
{
    use SiteBasedTestTrait;

    /**
     * Required by SiteBasedTestTrait. Override in a subclass to add languages.
     *
     * @var array<string, array<string, mixed>>
     */
    protected const LANGUAGE_PRESETS = [
        'EN' => ['id' => 0, 'title' => 'English', 'locale' => 'en_US.UTF8'],
        'DE' => ['id' => 1, 'title' => 'German', 'locale' => 'de_DE.UTF8', 'fallbackType' => 'fallback', 'fallbacks' => 'EN'],
        'DA' => ['id' => 2, 'title' => 'Danish', 'locale' => 'da_DA.UTF8', 'fallbackType' => 'strict'],
    ];

    /**
     * Only what EXT:solr itself requires. A test needing more — cms-install or
     * cms-fluid-styled-content, which EXT:solr keeps in require-dev and therefore
     * does not install for its consumers — declares it in its own subclass and
     * requires the package.
     */
    protected array $coreExtensionsToLoad = [
        'typo3/cms-reports',
        'typo3/cms-scheduler',
        'typo3/cms-tstemplate',
    ];

    protected array $testExtensionsToLoad = [
        'apache-solr-for-typo3/solr',
    ];

    /**
     * Language of the skeleton core this class's core is built from, which decides
     * the schema. One of the directory names under EXT:solr's Resources/Private/Solr/cores.
     */
    protected string $solrLanguage = 'english';

    private ?SolrServer $solrServer = null;

    private ?SolrSchema $solrSchema = null;

    private string $solrCoreName = '';

    protected function setUp(): void
    {
        parent::setUp();

        $skeleton = SolrSkeleton::fromInstalledExtension();
        $this->solrCoreName = 'core_' . static::getInstanceIdentifier();

        $this->getSolrServer()->createCore(
            $this->solrCoreName,
            $skeleton->getConfigSet($this->solrLanguage),
            $skeleton->getSchema($this->solrLanguage),
        );
    }

    protected function tearDown(): void
    {
        if ($this->solrCoreName !== '') {
            $this->getSolrServer()->unloadCore($this->solrCoreName);
            $this->solrCoreName = '';
        }
        $this->solrSchema = null;

        $this->resetSolrStaticCaches();

        parent::tearDown();
    }

    protected function getSolrCoreName(): string
    {
        return $this->solrCoreName;
    }

    protected function getSolrServer(): SolrServer
    {
        return $this->solrServer ??= SolrServer::fromEnvironment();
    }

    /**
     * Loads Solr documents from a YAML fixture, the way importCSVDataSet() loads
     * records from a CSV one.
     *
     * @throws InvalidSolrDataSetException
     */
    protected function importSolrDataSet(string $path): void
    {
        $dataSet = SolrDataSet::fromFile(
            $path,
            $this->getSolrSchema(),
            fn(int $rootPageId): string => $this->buildSolrSiteHash($rootPageId),
        );

        $this->getSolrServer()->addDocuments($this->solrCoreName, $dataSet->getDocuments());

        // Parse-time checks only catch what someone thought to look for. Comparing
        // the counts catches silent loss whatever caused it.
        $indexed = $this->getSolrServer()->countDocuments($this->solrCoreName);
        if ($indexed < $dataSet->count()) {
            self::fail(sprintf(
                'Solr holds %d of the %d documents "%s" declares. Check for documents resolving to the same id.',
                $indexed,
                $dataSet->count(),
                $path,
            ));
        }
    }

    /**
     * Compares the index with a YAML fixture, the way assertCSVDataSet() compares
     * the database with a CSV one.
     *
     * Only the fields a fixture lists are compared — a Solr document carries dozens,
     * and demanding all of them would make every assertion unmaintainable.
     *
     * The comparison is scoped to the document types the fixture lists, so asserting
     * three pages fails when the index holds six of them, while documents of other
     * types are left alone. It compares a fixture with the index and takes nothing
     * else; to assert what a search returns, see {@see assertSolrSearchResults()}.
     *
     * @throws InvalidSolrDataSetException
     */
    protected function assertSolrDataSet(string $path): void
    {
        $this->assertSolrDataSetWithin($path, null);
    }

    /**
     * @param string|null $searchQuery what a visitor searched for, or null to compare
     *                                 against the types the fixture lists
     */
    private function assertSolrDataSetWithin(string $path, ?string $searchQuery): void
    {
        $schema = $this->getSolrSchema();
        $expectations = SolrDataSet::expectationsFromFile(
            $path,
            $schema,
            fn(int $rootPageId): string => $this->buildSolrSiteHash($rootPageId),
        );

        $failures = [];
        foreach ($this->buildSolrAssertionScopes($expectations, $searchQuery) as $scope) {
            $failures = [...$failures, ...$this->compareSolrScope($scope, $expectations, $schema, $path)];
        }

        if ($failures !== []) {
            self::fail(sprintf('Asserting "%s":%s%s', $path, PHP_EOL, implode(PHP_EOL, $failures)));
        }

        // Keeps an empty expectation from tripping "did not perform any assertions".
        // Written the way the testing framework writes it in assertCSVDataSet().
        self::assertThat(true, self::isTrue());
    }

    /**
     * One scope per document type, each fetched with its own single-clause filter.
     *
     * This is what assertCSVDataSet() does with its tables, and it avoids composing
     * a query at all: one clause can never trip the `mm` the request handler sets,
     * and a failure names the type it came from.
     *
     * @return list<array{label: string, query: string, filter: string|null, documents: list<array<string, mixed>>}>
     */
    private function buildSolrAssertionScopes(SolrDataSet $expectations, ?string $searchQuery): array
    {
        if ($searchQuery !== null) {
            return [[
                'label' => 'the search "' . $searchQuery . '"',
                'query' => $searchQuery,
                'filter' => null,
                'documents' => $expectations->getDocuments(),
            ]];
        }

        $groups = $expectations->groupByType();
        if ($groups === null) {
            return [[
                'label' => 'the whole index',
                'query' => '*:*',
                'filter' => null,
                'documents' => $expectations->getDocuments(),
            ]];
        }

        $scopes = [];
        foreach ($groups as $type => $documents) {
            $scopes[] = [
                'label' => 'type "' . $type . '"',
                'query' => '*:*',
                'filter' => 'type:"' . $type . '"',
                'documents' => $documents,
            ];
        }

        return $scopes;
    }

    /**
     * @param array{label: string, query: string, filter: string|null, documents: list<array<string, mixed>>} $scope
     * @return list<string>
     */
    private function compareSolrScope(array $scope, SolrDataSet $expectations, SolrSchema $schema, string $path): array
    {
        $uniqueKey = $schema->getUniqueKey();
        $remaining = $this->getSolrServer()->findDocuments(
            $this->solrCoreName,
            $scope['query'],
            $scope['filter'],
            $uniqueKey,
        );
        $failures = [];

        foreach ($scope['documents'] as $expected) {
            $id = (string)$expected[$uniqueKey];

            if (!isset($remaining[$id])) {
                $failures[] = sprintf('Document "%s" not found in %s.', $id, $scope['label']);
                continue;
            }

            $differences = $this->findSolrFieldDifferences($expected, $remaining[$id], $schema, $path);
            if ($differences !== []) {
                $failures[] = sprintf(
                    'Assertion in data-set failed for "%s":%s%s',
                    $id,
                    PHP_EOL,
                    implode(PHP_EOL, $differences),
                );
            }

            unset($remaining[$id]);
        }

        // What no positive assertion can see: the index holding documents the fixture
        // never mentioned. Reduced to the fields the fixture declares, the way
        // assertCSVDataSet() renders an unexpected record — a whole Solr document
        // carries scoring, versions and copy fields nobody wrote.
        $assertedFields = $expectations->getAssertedFieldNames();
        foreach ($remaining as $id => $document) {
            $reduced = $assertedFields === []
                ? $document
                : array_intersect_key($document, array_flip($assertedFields));

            $failures[] = sprintf(
                'Not asserted document found in %s for "%s": %s',
                $scope['label'],
                $id,
                json_encode($reduced, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     * @return list<string>
     */
    private function findSolrFieldDifferences(
        array $expected,
        array $actual,
        SolrSchema $schema,
        string $path,
    ): array {
        $differences = [];

        foreach ($expected as $field => $expectedValue) {
            if (!$schema->isStored($field)) {
                throw new InvalidSolrDataSetException(
                    sprintf(
                        '"%s" expects "%s", which the schema indexes without storing, so a query never returns it. '
                        . 'Assert it with countDocuments() instead.',
                        $path,
                        $field,
                    ),
                    1789398800,
                );
            }

            // "\*" asserts the field is present without pinning its value, as the
            // CSV data set does — for a timestamp, or anything a test cannot predict.
            if (is_string($expectedValue) && str_starts_with($expectedValue, '\\*')) {
                continue;
            }

            $actualValue = $actual[$field] ?? null;
            if ($actualValue !== $expectedValue) {
                $differences[] = sprintf(
                    '    %s: expected %s, got %s',
                    $field,
                    json_encode($expectedValue, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    json_encode($actualValue, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                );
            }
        }

        return $differences;
    }

    /**
     * Asserts what searching for a term returns, rather than what a Solr query
     * selects.
     *
     * The term is escaped the way EXT:solr escapes what a visitor typed, so a colon
     * or a dash in it stays text instead of turning into query syntax. It then goes
     * to the same request handler a search does, which means edismax over the
     * configured fields and the analysis chain of this core's language — a search
     * for the singular reaches a document holding the plural, which no field query
     * would.
     */
    protected function assertSolrSearchResults(string $searchTerm, string $path): void
    {
        $this->assertSolrDataSetWithin($path, $this->buildSolrSearchQuery($searchTerm));
    }

    protected function assertSolrSearchCount(string $searchTerm, int $expectedCount, string $message = ''): void
    {
        self::assertSame(
            $expectedCount,
            $this->getSolrServer()->countDocuments(
                $this->solrCoreName,
                $this->buildSolrSearchQuery($searchTerm),
            ),
            $message !== '' ? $message : sprintf('Searching for "%s" returned an unexpected number of documents.', $searchTerm),
        );
    }

    protected function buildSolrSearchQuery(string $searchTerm): string
    {
        // EscapeService returns the value it was given when it is numeric, so the
        // return type is wider than the string a query has to be.
        return (string)EscapeService::escape($searchTerm);
    }

    /**
     * The id EXT:solr gives a record, so a test can look a document up without
     * repeating the derivation.
     */
    protected function buildSolrDocumentId(string $type, int $uid, int $rootPageId): string
    {
        return sprintf('%s/%s/%d', $this->buildSolrSiteHash($rootPageId), $type, $uid);
    }

    protected function buildSolrSiteHash(int $rootPageId): string
    {
        // By root page rather than by page: the fixture names the root page, and a
        // rootline lookup would additionally require that page to exist as a record.
        return $this->get(SiteHashService::class)->getSiteHash(
            $this->get(SiteFinder::class)->getSiteByRootPageId($rootPageId),
        );
    }

    protected function getSolrSchema(): SolrSchema
    {
        return $this->solrSchema ??= $this->getSolrServer()->getSchema($this->solrCoreName);
    }

    protected function assertSolrIsEmpty(): void
    {
        $this->assertSolrContainsDocumentCount(0);
    }

    /**
     * Counts the whole core unless $query narrows it, so it can be scoped the same
     * way assertSolrDataSet() is — counting everything is misleading as soon as more
     * than one document type is indexed.
     */
    protected function assertSolrContainsDocumentCount(
        int $expectedCount,
        string $message = '',
        ?string $query = null,
    ): void {
        self::assertSame(
            $expectedCount,
            $this->getSolrServer()->countDocuments($this->solrCoreName, '*:*', $query),
            $message !== '' ? $message : sprintf(
                'Solr core "%s" holds an unexpected number of documents matching "%s".',
                $this->solrCoreName,
                $query ?? '*:*',
            ),
        );
    }

    protected function cleanUpSolrCoreAndAssertEmpty(): void
    {
        $this->getSolrServer()->deleteAllDocuments($this->solrCoreName);
        $this->assertSolrIsEmpty();
    }

    /**
     * EXT:solr holds three caches in static properties. A static property is not a
     * singleton, so GeneralUtility::purgeInstances() leaves it alone and it lives
     * for the whole PHPUnit process — carrying a resolved core name, a whole site
     * object, or a connection into the next test class.
     *
     * The failure this prevents never mentions Solr: a *different* test class
     * fails, and passes again when run on its own.
     */
    private function resetSolrStaticCaches(): void
    {
        ConnectionManager::resetConnections();
        SiteUtility::reset();
        TwoLevelCache::flushAllCaches();
    }
}
