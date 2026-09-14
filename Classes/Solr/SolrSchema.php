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

namespace Calien\SolrTesting\Solr;

/**
 * The schema of one core, as the server reports it.
 *
 * Read per core, never once per run: a configset carries one schema per language,
 * so two cores on the same server can declare different fields. Nothing here
 * assumes the schema EXT:solr ships — a project bringing its own is a supported
 * case.
 */
final class SolrSchema
{
    /**
     * @param array<string, array<string, mixed>> $fields declared fields, by name
     * @param array<string, array<string, mixed>> $dynamicFields dynamic fields, by pattern
     */
    public function __construct(
        private readonly string $uniqueKey,
        private readonly array $fields,
        private readonly array $dynamicFields,
    ) {}

    public function getUniqueKey(): string
    {
        return $this->uniqueKey;
    }

    public function hasField(string $name): bool
    {
        return isset($this->fields[$name]) || $this->matchDynamicField($name) !== null;
    }

    /**
     * A field the schema does not declare at all is not multi-valued, but callers
     * ask {@see hasField()} first, so an unknown name never reaches this.
     */
    public function isMultiValued(string $name): bool
    {
        $definition = $this->fields[$name] ?? $this->matchDynamicField($name) ?? [];

        return ($definition['multiValued'] ?? false) === true;
    }

    /**
     * A field the schema indexes without storing is searchable but never returned
     * by a select, so it can be queried for but not read back.
     */
    public function isStored(string $name): bool
    {
        $definition = $this->fields[$name] ?? $this->matchDynamicField($name) ?? [];

        return ($definition['stored'] ?? false) === true;
    }

    /**
     * Names of the fields the schema refuses a document without.
     *
     * Only declared fields can be required; a dynamic pattern never is.
     *
     * @return list<string>
     */
    public function getRequiredFields(): array
    {
        $required = [];

        foreach ($this->fields as $name => $definition) {
            if (($definition['required'] ?? false) === true) {
                $required[] = $name;
            }
        }

        return $required;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function matchDynamicField(string $name): ?array
    {
        foreach ($this->dynamicFields as $pattern => $definition) {
            if (fnmatch($pattern, $name)) {
                return $definition;
            }
        }

        return null;
    }
}
