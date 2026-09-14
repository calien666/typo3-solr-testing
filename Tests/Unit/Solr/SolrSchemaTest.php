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

namespace Calien\SolrTesting\Tests\Unit\Solr;

use Calien\SolrTesting\Solr\SolrSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SolrSchemaTest extends UnitTestCase
{
    #[Test]
    public function reportsTheUniqueKey(): void
    {
        self::assertSame('id', $this->buildSchema()->getUniqueKey());
    }

    #[Test]
    #[DataProvider('fieldRecognitionDataProvider')]
    public function recognisesAField(string $field, bool $expected): void
    {
        self::assertSame($expected, $this->buildSchema()->hasField($field));
    }

    /**
     * @return \Generator<string, array{field: string, expected: bool}>
     */
    public static function fieldRecognitionDataProvider(): \Generator
    {
        yield 'a declared field' => ['field' => 'title', 'expected' => true];
        yield 'a field matching a dynamic pattern' => ['field' => 'author_stringS', 'expected' => true];
        yield 'a field matching nothing' => ['field' => 'titel', 'expected' => false];
    }

    #[Test]
    #[DataProvider('multiValueDataProvider')]
    public function reportsWhetherAFieldTakesSeveralValues(string $field, bool $expected): void
    {
        self::assertSame($expected, $this->buildSchema()->isMultiValued($field));
    }

    /**
     * @return \Generator<string, array{field: string, expected: bool}>
     */
    public static function multiValueDataProvider(): \Generator
    {
        yield 'a single-valued declared field' => ['field' => 'title', 'expected' => false];
        yield 'a multi-valued declared field' => ['field' => 'keywords', 'expected' => true];
        yield 'a single-valued dynamic pattern' => ['field' => 'author_stringS', 'expected' => false];
        yield 'a multi-valued dynamic pattern' => ['field' => 'author_stringM', 'expected' => true];
    }

    #[Test]
    #[DataProvider('storedDataProvider')]
    public function reportsWhetherAFieldIsStored(string $field, bool $expected): void
    {
        self::assertSame($expected, $this->buildSchema()->isStored($field));
    }

    /**
     * @return \Generator<string, array{field: string, expected: bool}>
     */
    public static function storedDataProvider(): \Generator
    {
        yield 'a stored field' => ['field' => 'title', 'expected' => true];
        yield 'an indexed but unstored field' => ['field' => 'appKey', 'expected' => false];
    }

    #[Test]
    public function listsTheRequiredFields(): void
    {
        self::assertSame(['id', 'appKey', 'type'], $this->buildSchema()->getRequiredFields());
    }

    /**
     * A dynamic pattern is never required, however it is declared.
     */
    #[Test]
    public function doesNotTreatADynamicPatternAsRequired(): void
    {
        self::assertNotContains('*_stringM', $this->buildSchema()->getRequiredFields());
    }

    /**
     * A schema declaring "*" matches every name, so nothing is ever unknown against
     * it — which is why the unknown-field check is inert against the schema EXT:solr
     * ships, and can only be exercised here.
     */
    #[Test]
    public function matchesEveryNameWhenACatchAllPatternIsDeclared(): void
    {
        $schema = new SolrSchema('id', [], ['*' => ['name' => '*', 'type' => 'text']]);

        self::assertTrue($schema->hasField('anythingAtAll'));
    }

    private function buildSchema(): SolrSchema
    {
        return new SolrSchema(
            'id',
            [
                'id' => ['name' => 'id', 'type' => 'string', 'stored' => true, 'required' => true],
                'appKey' => ['name' => 'appKey', 'type' => 'string', 'stored' => false, 'required' => true],
                'type' => ['name' => 'type', 'type' => 'string', 'stored' => true, 'required' => true],
                'title' => ['name' => 'title', 'type' => 'text', 'stored' => true],
                'keywords' => ['name' => 'keywords', 'type' => 'text', 'stored' => true, 'multiValued' => true],
            ],
            [
                '*_stringS' => ['name' => '*_stringS', 'type' => 'string', 'multiValued' => false],
                '*_stringM' => ['name' => '*_stringM', 'type' => 'string', 'multiValued' => true],
            ],
        );
    }
}
