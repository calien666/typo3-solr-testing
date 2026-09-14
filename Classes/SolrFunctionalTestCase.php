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

    protected function assertSolrContainsDocumentCount(int $expectedCount, string $message = ''): void
    {
        self::assertSame(
            $expectedCount,
            $this->getSolrServer()->countDocuments($this->solrCoreName),
            $message !== '' ? $message : sprintf('Solr core "%s" holds an unexpected number of documents.', $this->solrCoreName),
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
