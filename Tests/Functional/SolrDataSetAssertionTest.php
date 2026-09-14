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

use Calien\SolrTesting\Exception\InvalidSolrDataSetException;
use Calien\SolrTesting\SolrFunctionalTestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;

final class SolrDataSetAssertionTest extends SolrFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->writeSiteConfiguration(
            'test-site',
            $this->buildSiteConfiguration(1, 'https://testing.example/'),
            [$this->buildDefaultLanguageConfiguration('EN', '/')],
        );
    }

    #[Test]
    public function passesWhenTheIndexMatchesTheFixture(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/two-documents.yaml');

        $this->assertSolrDataSet(__DIR__ . '/Fixtures/expected-two-documents.yaml');
    }

    #[Test]
    public function anEmptyIndexAssertedAgainstAnEmptyExpectationCountsAsAnAssertion(): void
    {
        $this->assertSolrIsEmpty();
    }

    #[Test]
    public function reportsADocumentTheIndexDoesNotHold(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/two-documents.yaml');

        $message = $this->captureAssertionFailure(
            fn() => $this->assertSolrDataSet(__DIR__ . '/Fixtures/expected-missing-document.yaml'),
        );

        self::assertStringContainsString('not found in type "pages"', $message);
        self::assertStringContainsString('/pages/12', $message);
    }

    #[Test]
    public function reportsADocumentWhoseFieldDiffers(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/two-documents.yaml');

        $message = $this->captureAssertionFailure(
            fn() => $this->assertSolrDataSet(__DIR__ . '/Fixtures/expected-differing-title.yaml'),
        );

        self::assertStringContainsString('Assertion in data-set failed', $message);
        self::assertStringContainsString('Not the title that was imported', $message);
        self::assertStringContainsString('A page', $message);
    }

    /**
     * The leftover check is the one no positive assertion can replace: a fixture
     * asserting two documents passes happily while the index holds twenty.
     */
    #[Test]
    public function reportsADocumentNoAssertionCovered(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/two-documents.yaml');

        $message = $this->captureAssertionFailure(
            fn() => $this->assertSolrDataSet(__DIR__ . '/Fixtures/expected-only-one.yaml'),
        );

        self::assertStringContainsString('Not asserted document found', $message);
        self::assertStringContainsString('/pages/11', $message);
    }

    #[Test]
    public function reportsEveryFailureAtOnce(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/two-documents.yaml');

        $message = $this->captureAssertionFailure(
            fn() => $this->assertSolrDataSet(__DIR__ . '/Fixtures/expected-only-one.yaml'),
        );

        // One leftover here; the point is that reporting does not stop at the first
        // problem, so a run tells you everything that is wrong.
        self::assertSame(1, substr_count($message, 'Not asserted document found'));
    }

    /**
     * Without a query the scope comes from the fixture: it lists pages, so the three
     * news entries indexed alongside are none of its business.
     */
    #[Test]
    public function scopesTheComparisonToTheTypesTheFixtureLists(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/six-documents.yaml');

        $this->assertSolrDataSet(__DIR__ . '/Fixtures/expected-three-pages.yaml');
    }

    /**
     * The case that prompted the scoping: three pages asserted, six in the index.
     */
    #[Test]
    public function reportsAnExtraDocumentOfAnAssertedType(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/six-documents.yaml');

        $message = $this->captureAssertionFailure(
            fn() => $this->assertSolrDataSet(__DIR__ . '/Fixtures/expected-two-pages.yaml'),
        );

        self::assertSame(1, substr_count($message, 'Not asserted document found'));
        self::assertStringContainsString('/pages/12', $message);
    }

    /**
     * One fixture holding several types, the way a CSV data set holds several
     * tables: every type it names is compared against, and only those.
     */
    #[Test]
    public function comparesAgainstEveryTypeTheFixtureLists(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/six-documents.yaml');

        $this->assertSolrDataSet(__DIR__ . '/Fixtures/expected-six-documents.yaml');
    }

    #[Test]
    public function reportsAnUncoveredDocumentOfTheSecondTypeInTheFixture(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/six-documents.yaml');

        $message = $this->captureAssertionFailure(
            fn() => $this->assertSolrDataSet(__DIR__ . '/Fixtures/expected-five-documents.yaml'),
        );

        self::assertSame(1, substr_count($message, 'Not asserted document found'));
        self::assertStringContainsString('/tx_news_domain_model_news/15', $message);
    }

    #[Test]
    public function acceptsAnyValueForAWildcardField(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/two-documents.yaml');

        $this->assertSolrDataSet(__DIR__ . '/Fixtures/expected-wildcard.yaml');
    }

    /**
     * A Solr document carries scoring, versions and copy fields nobody wrote, so an
     * unexpected one has to be reported in the fixture's own terms to be readable.
     */
    #[Test]
    public function reportsAnUnexpectedDocumentWithOnlyTheAssertedFields(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/two-documents.yaml');

        $message = $this->captureAssertionFailure(
            fn() => $this->assertSolrDataSet(__DIR__ . '/Fixtures/expected-only-one.yaml'),
        );

        self::assertStringContainsString('"title":"Another page"', $message);
        self::assertStringNotContainsString('_version_', $message);
        self::assertStringNotContainsString('titleExact', $message);
    }

    #[Test]
    public function refusesToAssertAFieldTheSchemaDoesNotStore(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/two-documents.yaml');

        $this->expectException(InvalidSolrDataSetException::class);
        $this->expectExceptionCode(1789398800);

        $this->assertSolrDataSet(__DIR__ . '/Fixtures/expected-unstored-field.yaml');
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
