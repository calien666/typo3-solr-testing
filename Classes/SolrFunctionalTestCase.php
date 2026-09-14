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
use ApacheSolrForTypo3\Solr\System\Cache\TwoLevelCache;
use ApacheSolrForTypo3\Solr\System\Util\SiteUtility;
use Calien\SolrTesting\Solr\SolrServer;
use Calien\SolrTesting\Solr\SolrSkeleton;
use SBUERK\TYPO3\Testing\SiteHandling\SiteBasedTestTrait;
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
