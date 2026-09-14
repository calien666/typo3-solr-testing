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

/**
 * Constants that only exist once a TYPO3 request or a test run has bootstrapped.
 * Defining them here lets PHPStan resolve references to them without a running
 * instance.
 */
defined('TYPO3') || define('TYPO3', true);
defined('LF') || define('LF', chr(10));
defined('CR') || define('CR', chr(13));
defined('CRLF') || define('CRLF', CR . LF);

// Defined by TYPO3\TestingFramework\Core\Testbase::defineOriginalRootPath() when a
// functional test run bootstraps, so it never exists during static analysis.
defined('ORIGINAL_ROOT') || define('ORIGINAL_ROOT', '/');
