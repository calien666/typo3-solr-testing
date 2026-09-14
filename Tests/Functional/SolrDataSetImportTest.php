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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class SolrDataSetImportTest extends SolrFunctionalTestCase
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
    public function importsEveryDocumentInTheFile(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/two-documents.yaml');

        $this->assertSolrContainsDocumentCount(2);
    }

    #[Test]
    public function derivesTheIdFromSiteHashTypeAndUid(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/two-documents.yaml');

        $document = $this->getSolrServer()->findDocumentById(
            $this->getSolrCoreName(),
            $this->buildSolrDocumentId('pages', 10, 1),
        );

        self::assertSame('A page', $document['title'] ?? null);
    }

    #[Test]
    public function derivesTheAppKey(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/two-documents.yaml');

        // Queried rather than read back: the schema indexes appKey without storing
        // it, so a select never returns it.
        self::assertSame(
            2,
            $this->getSolrServer()->countDocuments($this->getSolrCoreName(), 'appKey:"EXT:solr"'),
        );
    }

    #[Test]
    public function keepsAMultiValuedFieldAsAList(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/two-documents.yaml');

        $document = $this->getSolrServer()->findDocumentById(
            $this->getSolrCoreName(),
            $this->buildSolrDocumentId('pages', 11, 1),
        );

        self::assertSame(['alpha', 'beta'], $document['keywords'] ?? null);
    }

    #[Test]
    #[DataProvider('rejectedFixturesDataProvider')]
    public function rejectsAnInvalidFixture(string $fixture, int $expectedCode): void
    {
        $this->expectException(InvalidSolrDataSetException::class);
        $this->expectExceptionCode($expectedCode);

        $this->importSolrDataSet(__DIR__ . '/Fixtures/' . $fixture);
    }

    /**
     * @return \Generator<string, array{fixture: string, expectedCode: int}>
     */
    public static function rejectedFixturesDataProvider(): \Generator
    {
        yield 'two documents deriving the same id' => [
            'fixture' => 'duplicate-id.yaml',
            'expectedCode' => 1789398381,
        ];
        yield 'a scalar where the schema declares a multi-valued field' => [
            'fixture' => 'scalar-for-multivalued.yaml',
            'expectedCode' => 1789398384,
        ];
        yield 'a list where the schema declares a single-valued field' => [
            'fixture' => 'array-for-singlevalued.yaml',
            'expectedCode' => 1789398385,
        ];
        yield 'a document missing a field the schema marks required' => [
            'fixture' => 'no-type.yaml',
            'expectedCode' => 1789399782,
        ];
        yield 'a document without the uid its id is derived from' => [
            'fixture' => 'missing-uid.yaml',
            'expectedCode' => 1789398387,
        ];
    }

    /**
     * Pins behaviour that looks like a gap and is not one: EXT:solr's schema
     * declares a "*" dynamic field, so every name matches something and the
     * unknown-field check cannot fire against it. It still guards a custom schema
     * without a catch-all.
     */
    #[Test]
    public function acceptsAnUnknownFieldBecauseTheShippedSchemaMatchesEverything(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/unknown-field.yaml');

        $this->assertSolrContainsDocumentCount(1);
    }

    #[Test]
    public function countsOnlyTheDocumentsMatchingAQuery(): void
    {
        $this->importSolrDataSet(__DIR__ . '/Fixtures/six-documents.yaml');

        $this->assertSolrContainsDocumentCount(6);
        $this->assertSolrContainsDocumentCount(3, '', 'type:pages');
    }

    #[Test]
    public function reportsAMissingFileWithItsPath(): void
    {
        $this->expectException(InvalidSolrDataSetException::class);
        $this->expectExceptionCode(1789398380);

        $this->importSolrDataSet(__DIR__ . '/Fixtures/there-is-no-such-file.yaml');
    }
}
