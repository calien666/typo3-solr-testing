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

namespace Calien\SolrTesting\Tests\Functional;

use Calien\SolrTesting\SolrFunctionalTestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;

final class SolrSearchAssertionTest extends SolrFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->writeSiteConfiguration(
            'test-site',
            $this->buildSiteConfiguration(1, 'https://testing.example/'),
            [$this->buildDefaultLanguageConfiguration('EN', '/')],
        );

        $this->importSolrDataSet(__DIR__ . '/Fixtures/searchable-documents.yaml');
    }

    #[Test]
    public function assertsWhatASearchTermReturns(): void
    {
        $this->assertSolrSearchResults('foxes', __DIR__ . '/Fixtures/expected-fox-result.yaml');
    }

    /**
     * The core is built from the English schema, so the analysis chain stems the
     * term. Searching the singular has to reach a document holding the plural, and
     * a raw field query never would.
     */
    #[Test]
    public function appliesTheAnalysisChainOfTheCore(): void
    {
        $this->assertSolrSearchResults('fox', __DIR__ . '/Fixtures/expected-fox-result.yaml');
    }

    #[Test]
    public function reportsADocumentTheSearchReturnedButTheFixtureDoesNotCover(): void
    {
        $message = $this->captureAssertionFailure(
            fn() => $this->assertSolrSearchResults('the', __DIR__ . '/Fixtures/expected-fox-result.yaml'),
        );

        self::assertStringContainsString('Not asserted document found', $message);
    }

    /**
     * A term a visitor typed is text, not query syntax, so the colon must not turn
     * into a field lookup against a field that does not exist.
     */
    #[Test]
    public function treatsTheTermAsTextRatherThanAsQuerySyntax(): void
    {
        $this->assertSolrSearchCount('nosuchfield:whatever', 0);
    }

    #[Test]
    public function countsWhatASearchTermReturns(): void
    {
        $this->assertSolrSearchCount('foxes', 1);
    }

    private function captureAssertionFailure(callable $assertion): string
    {
        try {
            $assertion();
        } catch (AssertionFailedError $failure) {
            return $failure->getMessage();
        }

        self::fail('The assertion was expected to fail, and did not.');
    }
}
