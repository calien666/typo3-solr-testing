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

namespace Calien\SolrTesting\Tests\Unit\DataSet;

use Calien\SolrTesting\DataSet\SolrDataSet;
use Calien\SolrTesting\Exception\InvalidSolrDataSetException;
use Calien\SolrTesting\Solr\SolrSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The fixture rules need no Solr and no TYPO3, so they are checked here rather than
 * through a container. The schema used declares no catch-all pattern, which is what
 * makes the unknown-field rule observable at all — the one EXT:solr ships matches
 * every name.
 */
final class SolrDataSetTest extends UnitTestCase
{
    #[Test]
    public function derivesTheIdFromSiteHashTypeAndUid(): void
    {
        $documents = $this->import('valid.yaml')->getDocuments();

        self::assertSame('hash-for-1/pages/10', $documents[0]['id']);
    }

    #[Test]
    public function derivesTheAppKeyForAnImport(): void
    {
        self::assertSame('EXT:solr', $this->import('valid.yaml')->getDocuments()[0]['appKey']);
    }

    #[Test]
    public function doesNotDeriveTheAppKeyForAnExpectation(): void
    {
        self::assertArrayNotHasKey('appKey', $this->expect('valid.yaml')->getDocuments()[0]);
    }

    #[Test]
    public function dropsTheControlKeyFromTheDocument(): void
    {
        self::assertArrayNotHasKey('rootPageId', $this->import('valid.yaml')->getDocuments()[0]);
    }

    #[Test]
    public function keepsAnIdGivenOutright(): void
    {
        self::assertSame('given/outright/1', $this->import('explicit-id.yaml')->getDocuments()[0]['id']);
    }

    #[Test]
    public function groupsDocumentsByType(): void
    {
        $groups = $this->expect('valid.yaml')->groupByType();

        self::assertSame(['pages', 'tx_news_domain_model_news'], array_keys($groups ?? []));
    }

    /**
     * An expectation may name a document by id alone, and then the types it covers
     * cannot be told — the caller widens the comparison rather than guessing.
     */
    #[Test]
    public function reportsNoGroupingWhenADocumentHasNoType(): void
    {
        self::assertNull($this->expect('no-type-expectation.yaml')->groupByType());
    }

    /**
     * The same file an import refuses, because type is required there.
     */
    #[Test]
    public function acceptsAnExpectationWithoutTheRequiredFields(): void
    {
        self::assertSame(1, $this->expect('missing-required-type.yaml')->count());
    }

    #[Test]
    public function listsEveryFieldAnyDocumentAsserts(): void
    {
        $names = $this->expect('valid.yaml')->getAssertedFieldNames();

        self::assertEqualsCanonicalizing(['type', 'uid', 'title', 'siteHash', 'id', 'keywords'], $names);
    }

    #[Test]
    public function countsItsDocuments(): void
    {
        self::assertSame(2, $this->import('valid.yaml')->count());
    }

    #[Test]
    #[DataProvider('rejectedDataProvider')]
    public function rejectsAnInvalidDataSet(string $fixture, int $expectedCode): void
    {
        $this->expectException(InvalidSolrDataSetException::class);
        $this->expectExceptionCode($expectedCode);

        $this->import($fixture);
    }

    /**
     * @return \Generator<string, array{fixture: string, expectedCode: int}>
     */
    public static function rejectedDataProvider(): \Generator
    {
        yield 'a file that does not exist' => [
            'fixture' => 'there-is-no-such-file.yaml',
            'expectedCode' => 1789398380,
        ];
        yield 'a file without a documents list' => [
            'fixture' => 'no-documents.yaml',
            'expectedCode' => 1789398382,
        ];
        yield 'an entry that is not a mapping' => [
            'fixture' => 'not-a-mapping.yaml',
            'expectedCode' => 1789398386,
        ];
        yield 'a field the schema declares nowhere' => [
            'fixture' => 'unknown-field.yaml',
            'expectedCode' => 1789398383,
        ];
        yield 'two entries resolving to the same id' => [
            'fixture' => 'duplicate-id.yaml',
            'expectedCode' => 1789398381,
        ];
        yield 'a single value where the schema declares several' => [
            'fixture' => 'scalar-for-multivalued.yaml',
            'expectedCode' => 1789398384,
        ];
        yield 'a list where the schema declares one value' => [
            'fixture' => 'array-for-singlevalued.yaml',
            'expectedCode' => 1789398385,
        ];
        yield 'nothing to derive an id from' => [
            'fixture' => 'missing-uid.yaml',
            'expectedCode' => 1789398387,
        ];
        yield 'a field the schema marks required' => [
            'fixture' => 'missing-required-type.yaml',
            'expectedCode' => 1789399782,
        ];
    }

    /**
     * The check the shipped schema can never exercise, because its "*" pattern
     * matches every name a typo could produce.
     */
    #[Test]
    public function acceptsAnUnknownFieldWhenTheSchemaDeclaresACatchAll(): void
    {
        $dataSet = SolrDataSet::fromFile(
            $this->fixture('unknown-field.yaml'),
            new SolrSchema('id', [], ['*' => ['name' => '*', 'multiValued' => false]]),
            static fn(int $rootPageId): string => 'hash-for-' . $rootPageId,
        );

        self::assertSame(1, $dataSet->count());
    }

    private function import(string $fixture): SolrDataSet
    {
        return SolrDataSet::fromFile(
            $this->fixture($fixture),
            $this->buildSchema(),
            static fn(int $rootPageId): string => 'hash-for-' . $rootPageId,
        );
    }

    private function expect(string $fixture): SolrDataSet
    {
        return SolrDataSet::expectationsFromFile(
            $this->fixture($fixture),
            $this->buildSchema(),
            static fn(int $rootPageId): string => 'hash-for-' . $rootPageId,
        );
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/Fixtures/' . $name;
    }

    private function buildSchema(): SolrSchema
    {
        return new SolrSchema(
            'id',
            [
                'id' => ['name' => 'id', 'stored' => true, 'required' => true],
                'appKey' => ['name' => 'appKey', 'stored' => false, 'required' => true],
                'type' => ['name' => 'type', 'stored' => true, 'required' => true],
                'uid' => ['name' => 'uid', 'stored' => true],
                'siteHash' => ['name' => 'siteHash', 'stored' => true],
                'title' => ['name' => 'title', 'stored' => true],
                'keywords' => ['name' => 'keywords', 'stored' => true, 'multiValued' => true],
            ],
            [],
        );
    }
}
