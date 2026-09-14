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

use Calien\SolrTesting\Exception\SolrRequestFailedException;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Talks to a Solr server over plain HTTP.
 *
 * Deliberately not Solarium: the client Solarium builds comes from the site
 * configuration these tests exist to verify, so it cannot diagnose its own
 * subject, and its major version differs between the branches of this package.
 */
final class SolrServer
{
    public function __construct(
        private readonly string $baseUri,
    ) {}

    public static function fromEnvironment(): self
    {
        $scheme = getenv('TESTING_SOLR_SCHEME') ?: 'http';
        $host = getenv('TESTING_SOLR_HOST') ?: 'localhost';
        $port = getenv('TESTING_SOLR_PORT') ?: '8983';

        return new self(sprintf('%s://%s:%s/solr', $scheme, $host, $port));
    }

    public function getBaseUri(): string
    {
        return $this->baseUri;
    }

    public function getCoreUri(string $coreName): string
    {
        return $this->baseUri . '/' . $coreName;
    }

    /**
     * @return list<string>
     */
    public function coreNames(): array
    {
        $status = $this->request('/admin/cores?action=STATUS&wt=json')['status'] ?? [];

        if (!is_array($status)) {
            return [];
        }

        return array_map(strval(...), array_keys($status));
    }

    public function createCore(string $coreName, string $configSet, string $schema): void
    {
        $this->request(sprintf(
            '/admin/cores?action=CREATE&name=%s&configSet=%s&schema=%s&dataDir=%s&wt=json',
            rawurlencode($coreName),
            rawurlencode($configSet),
            rawurlencode($schema),
            rawurlencode('/var/solr/data/data/' . $coreName),
        ));
    }

    public function unloadCore(string $coreName): void
    {
        $this->request(sprintf(
            '/admin/cores?action=UNLOAD&core=%s&deleteDataDir=true&deleteInstanceDir=true&wt=json',
            rawurlencode($coreName),
        ));
    }

    /**
     * Counts documents, optionally narrowed.
     *
     * $query is what a visitor searched for and goes through the handler's edismax
     * defaults. $filterQuery is structural — a type, an id — and must be a filter
     * rather than part of $query: the handler sets `mm`, so a `q` of two clauses
     * demands a document matching both, and `type:("a" OR "b")` therefore finds
     * nothing at all.
     */
    public function countDocuments(string $coreName, string $query = '*:*', ?string $filterQuery = null): int
    {
        $response = $this->request($this->buildSelectPath($coreName, $query, $filterQuery, 0));

        return (int)($response['response']['numFound'] ?? 0);
    }

    public function getSchema(string $coreName): SolrSchema
    {
        $uniqueKey = (string)($this->request('/' . $coreName . '/schema/uniquekey?wt=json')['uniqueKey'] ?? 'id');

        $fields = [];
        foreach ($this->request('/' . $coreName . '/schema/fields?wt=json')['fields'] ?? [] as $field) {
            if (is_array($field) && isset($field['name']) && is_string($field['name'])) {
                $fields[$field['name']] = $field;
            }
        }

        $dynamicFields = [];
        foreach ($this->request('/' . $coreName . '/schema/dynamicfields?wt=json')['dynamicFields'] ?? [] as $field) {
            if (is_array($field) && isset($field['name']) && is_string($field['name'])) {
                $dynamicFields[$field['name']] = $field;
            }
        }

        return new SolrSchema($uniqueKey, $fields, $dynamicFields);
    }

    /**
     * @return array<string, mixed>
     */
    public function findDocumentById(string $coreName, string $id): array
    {
        $response = $this->request(
            $this->buildSelectPath($coreName, '*:*', 'id:"' . $id . '"', 1),
        );
        $document = $response['response']['docs'][0] ?? [];

        return is_array($document) ? $document : [];
    }

    private function buildSelectPath(string $coreName, string $query, ?string $filterQuery, int $rows): string
    {
        $path = sprintf('/%s/select?q=%s&rows=%d&wt=json', $coreName, rawurlencode($query), $rows);

        if ($filterQuery !== null) {
            $path .= '&fq=' . rawurlencode($filterQuery);
        }

        return $path;
    }

    /**
     * Every document in the core, keyed by its unique id.
     *
     * @return array<string, array<string, mixed>>
     */
    /**
     * Documents keyed by their unique id. See {@see countDocuments()} for why a
     * structural narrowing belongs in $filterQuery rather than in $query.
     *
     * @return array<string, array<string, mixed>>
     */
    public function findDocuments(
        string $coreName,
        string $query = '*:*',
        ?string $filterQuery = null,
        string $uniqueKey = 'id',
    ): array {
        $response = $this->request($this->buildSelectPath($coreName, $query, $filterQuery, 10000));

        $documents = [];
        foreach ($response['response']['docs'] ?? [] as $document) {
            if (is_array($document) && isset($document[$uniqueKey])) {
                $documents[(string)$document[$uniqueKey]] = $document;
            }
        }

        return $documents;
    }

    /**
     * @param list<array<string, mixed>> $documents
     */
    public function addDocuments(string $coreName, array $documents): void
    {
        $body = json_encode($documents, JSON_THROW_ON_ERROR);

        // A bare array, not {"add": [...]}: the command form takes one document per
        // "add" key, and Solr reads an array there as a single malformed document.
        $this->post('/' . $coreName . '/update?commit=true&wt=json', $body);
    }

    /**
     * Deletes every document and commits, because a write is not visible until it
     * is committed and `plugin.tx_solr.index.enableCommits = 0` is a legitimate
     * production setting.
     */
    public function deleteAllDocuments(string $coreName): void
    {
        $this->post(
            '/' . $coreName . '/update?commit=true&wt=json',
            '{"delete":{"query":"*:*"}}',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $path): array
    {
        $response = GeneralUtility::makeInstance(RequestFactory::class)
            ->request($this->baseUri . $path, 'GET', ['http_errors' => false]);

        return $this->decode($path, $response->getStatusCode(), $response->getBody()->getContents());
    }

    /**
     * @return array<string, mixed>
     */
    private function post(string $path, string $body): array
    {
        $response = GeneralUtility::makeInstance(RequestFactory::class)->request(
            $this->baseUri . $path,
            'POST',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $body,
                'http_errors' => false,
            ],
        );

        return $this->decode($path, $response->getStatusCode(), $response->getBody()->getContents());
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $path, int $statusCode, string $body): array
    {
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new SolrRequestFailedException(
                sprintf('Solr answered %d for "%s" with a body that is not JSON: %s', $statusCode, $path, $body),
                1789205531,
            );
        }

        // Solr reports its own failures in the body, so the status code alone is
        // not enough to tell a refused request from a served one.
        if ($statusCode >= 400 || isset($decoded['error'])) {
            throw new SolrRequestFailedException(
                sprintf(
                    'Solr refused "%s" with status %d: %s',
                    $path,
                    $statusCode,
                    $decoded['error']['msg'] ?? $body,
                ),
                1789205532,
            );
        }

        return $decoded;
    }
}
