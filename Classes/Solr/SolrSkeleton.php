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

use Calien\SolrTesting\Exception\SolrSkeletonNotFoundException;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Reads the Solr skeleton EXT:solr ships, to learn which configset and schema a
 * core has to be created from.
 *
 * Solr's own Configsets API cannot answer this: it is SolrCloud-only and replies
 * "Solr instance is not running in SolrCloud mode" to a server started
 * `--user-managed`, which is how both supported images run. The names are
 * version-stamped (`ext_solr_14_0_0`), so they are read from the installed
 * extension rather than assumed.
 */
final class SolrSkeleton
{
    public function __construct(
        private readonly string $path,
    ) {}

    public static function fromInstalledExtension(): self
    {
        return new self(ExtensionManagementUtility::extPath('solr') . 'Resources/Private/Solr');
    }

    public function getConfigSet(string $language): string
    {
        return $this->readProperty($language, 'configSet');
    }

    /**
     * The schema is per language rather than per configset, so field sets can
     * differ between cores on one server.
     */
    public function getSchema(string $language): string
    {
        return $this->readProperty($language, 'schema');
    }

    private function readProperty(string $language, string $property): string
    {
        $file = $this->path . '/cores/' . $language . '/core.properties';

        if (!is_readable($file)) {
            throw new SolrSkeletonNotFoundException(
                sprintf('Cannot read "%s". Is EXT:solr installed and is "%s" one of its cores?', $file, $language),
                1789397888,
            );
        }

        $properties = parse_ini_file($file, false, INI_SCANNER_RAW);

        if (!is_array($properties) || !isset($properties[$property]) || !is_string($properties[$property])) {
            throw new SolrSkeletonNotFoundException(
                sprintf('Property "%s" is missing from "%s".', $property, $file),
                1789397889,
            );
        }

        return $properties[$property];
    }
}
