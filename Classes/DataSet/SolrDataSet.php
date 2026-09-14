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

namespace Calien\SolrTesting\DataSet;

use Calien\SolrTesting\Exception\InvalidSolrDataSetException;
use Calien\SolrTesting\Solr\SolrSchema;
use Symfony\Component\Yaml\Yaml;

/**
 * A set of Solr documents read from a YAML fixture, with the derived fields filled
 * in and every document checked against the schema of the core it is going into.
 *
 * The strictness mirrors the testing framework's `importCSVDataSet()`, which gets
 * its own from TCA defaults and from the database rejecting an unknown column. A
 * Solr document has no such backstop: the server accepts a scalar for a
 * multi-valued field by quietly wrapping it, and overwrites silently on a repeated
 * id. Both are checked here, because nothing downstream would report them.
 */
final class SolrDataSet
{
    private const DERIVED_APP_KEY = 'EXT:solr';

    /**
     * Keys that describe a document rather than being fields of it.
     */
    private const CONTROL_KEYS = ['rootPageId'];

    /**
     * @param list<array<string, mixed>> $documents
     */
    private function __construct(
        private readonly array $documents,
    ) {}

    /**
     * @param callable(int): string $siteHashResolver resolves a root page id to its site hash
     */
    public static function fromFile(
        string $path,
        SolrSchema $schema,
        callable $siteHashResolver,
    ): self {
        if (!is_readable($path)) {
            throw new InvalidSolrDataSetException(
                sprintf('Solr data set "%s" does not exist or cannot be read.', $path),
                1789398380,
            );
        }

        $parsed = Yaml::parseFile($path);
        $rawDocuments = is_array($parsed) ? ($parsed['documents'] ?? null) : null;

        if (!is_array($rawDocuments)) {
            throw new InvalidSolrDataSetException(
                sprintf('Solr data set "%s" has no "documents" list.', $path),
                1789398382,
            );
        }

        $documents = [];
        $seenIds = [];

        foreach (array_values($rawDocuments) as $index => $rawDocument) {
            if (!is_array($rawDocument)) {
                throw new InvalidSolrDataSetException(
                    sprintf('Document %d in "%s" is not a mapping.', $index, $path),
                    1789398386,
                );
            }

            $document = self::derive($rawDocument, $path, $index, $siteHashResolver);
            self::validateAgainstSchema($document, $schema, $path, $index);

            $id = (string)$document[$schema->getUniqueKey()];
            if (isset($seenIds[$id])) {
                throw new InvalidSolrDataSetException(
                    sprintf(
                        'Documents %d and %d in "%s" both resolve to id "%s". Solr would silently overwrite, '
                        . 'so the index would hold fewer documents than the file lists.',
                        $seenIds[$id],
                        $index,
                        $path,
                        $id,
                    ),
                    1789398381,
                );
            }
            $seenIds[$id] = $index;

            $documents[] = $document;
        }

        return new self($documents);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDocuments(): array
    {
        return $this->documents;
    }

    public function count(): int
    {
        return count($this->documents);
    }

    /**
     * @param array<string, mixed> $rawDocument
     * @param callable(int): string $siteHashResolver
     * @return array<string, mixed>
     */
    private static function derive(
        array $rawDocument,
        string $path,
        int $index,
        callable $siteHashResolver,
    ): array {
        $document = $rawDocument;
        foreach (self::CONTROL_KEYS as $controlKey) {
            unset($document[$controlKey]);
        }

        $document['appKey'] ??= self::DERIVED_APP_KEY;

        if (isset($rawDocument['rootPageId'])) {
            $document['siteHash'] ??= $siteHashResolver((int)$rawDocument['rootPageId']);
        }

        if (isset($document['id'])) {
            return $document;
        }

        foreach (['siteHash', 'type', 'uid'] as $required) {
            if (!isset($document[$required]) || $document[$required] === '') {
                throw new InvalidSolrDataSetException(
                    sprintf(
                        'Document %d in "%s" has no "id" and no "%s" to derive one from. '
                        . 'Give it rootPageId, type and uid, or state the id outright.',
                        $index,
                        $path,
                        $required,
                    ),
                    1789398387,
                );
            }
        }

        $document['id'] = sprintf('%s/%s/%s', $document['siteHash'], $document['type'], $document['uid']);

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function validateAgainstSchema(
        array $document,
        SolrSchema $schema,
        string $path,
        int $index,
    ): void {
        foreach ($document as $field => $value) {
            if (!$schema->hasField($field)) {
                throw new InvalidSolrDataSetException(
                    sprintf(
                        'Document %d in "%s" sets "%s", which the schema declares neither as a field nor as a '
                        . 'dynamic pattern.',
                        $index,
                        $path,
                        $field,
                    ),
                    1789398383,
                );
            }

            $isMultiValued = $schema->isMultiValued($field);

            if ($isMultiValued && !is_array($value)) {
                throw new InvalidSolrDataSetException(
                    sprintf(
                        'Document %d in "%s" gives "%s" a single value, but the schema declares it multi-valued. '
                        . 'Solr would wrap it silently, leaving fixture and index disagreeing.',
                        $index,
                        $path,
                        $field,
                    ),
                    1789398384,
                );
            }

            if (!$isMultiValued && is_array($value)) {
                throw new InvalidSolrDataSetException(
                    sprintf(
                        'Document %d in "%s" gives "%s" a list, but the schema declares it single-valued.',
                        $index,
                        $path,
                        $field,
                    ),
                    1789398385,
                );
            }
        }
    }
}
