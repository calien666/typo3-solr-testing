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
use PHPUnit\Framework\Attributes\Test;

/**
 * Paired with {@see SolrCoreIsolationTest}, which does exactly the same.
 *
 * Both write one document and assert they see one. Sharing a core makes whichever
 * runs second see two, so the pair fails as soon as isolation stops working. Run
 * them together — either alone passes regardless.
 */
final class SolrCoreIsolationSecondTest extends SolrFunctionalTestCase
{
    #[Test]
    public function seesOnlyItsOwnDocument(): void
    {
        $this->getSolrServer()->addDocuments($this->getSolrCoreName(), [
            ['id' => 'isolation/second', 'type' => 'pages', 'appKey' => 'EXT:solr'],
        ]);

        $this->assertSolrContainsDocumentCount(1);
    }
}
