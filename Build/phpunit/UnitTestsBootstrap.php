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
 * Unit test phpunit bootstrap, defined in UnitTests.xml and called by phpunit
 * before the test suites are instantiated.
 *
 * Copied from typo3/testing-framework's boilerplate
 * (Resources/Core/Build/UnitTestsBootstrap.php), whose header asks extensions to
 * copy it rather than reference it.
 *
 * Run the suite through `Build/Scripts/runTests.sh -s unit`.
 */

use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder as CoreSystemEnvironmentBuilder;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Package\UnitTestPackageManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\SystemEnvironmentBuilder;
use TYPO3\TestingFramework\Core\Testbase;

(static function (): void {
    $testbase = new Testbase();

    if (!getenv('TYPO3_PATH_ROOT')) {
        putenv('TYPO3_PATH_ROOT=' . rtrim($testbase->getWebRoot(), '/'));
    }
    if (!getenv('TYPO3_PATH_WEB')) {
        putenv('TYPO3_PATH_WEB=' . rtrim($testbase->getWebRoot(), '/'));
    }

    $testbase->defineSitePath();

    // This branch targets TYPO3 14 only, so the consolidated HTTP entry point always
    // exists and REQUESTTYPE_CLI is the correct request type. The upstream boilerplate
    // keeps a v12 fallback behind a class_exists() check on an unimported class name,
    // which never matches. Composer mode is likewise always on: the testing framework
    // cannot run without composer.
    SystemEnvironmentBuilder::run(0, CoreSystemEnvironmentBuilder::REQUESTTYPE_CLI, true);

    $testbase->createDirectory(Environment::getPublicPath() . '/typo3conf/ext');
    $testbase->createDirectory(Environment::getPublicPath() . '/typo3temp/assets');
    $testbase->createDirectory(Environment::getPublicPath() . '/typo3temp/var/tests');
    $testbase->createDirectory(Environment::getPublicPath() . '/typo3temp/var/transient');

    $classLoader = require $testbase->getPackagesPath() . '/autoload.php';
    Bootstrap::initializeClassLoader($classLoader);

    $configurationManager = new ConfigurationManager();
    $GLOBALS['TYPO3_CONF_VARS'] = $configurationManager->getDefaultConfiguration();

    // On TYPO3 14 NullBackend implements the backend interfaces directly and has no
    // constructor, unlike the v13-era boilerplate which still passes ('production', []).
    $cache = new PhpFrontend(
        'core',
        new NullBackend(),
    );
    $packageManager = Bootstrap::createPackageManager(
        UnitTestPackageManager::class,
        Bootstrap::createPackageCache($cache),
    );

    GeneralUtility::setSingletonInstance(PackageManager::class, $packageManager);
    ExtensionManagementUtility::setPackageManager($packageManager);

    $testbase->dumpClassLoadingInformation();

    GeneralUtility::purgeInstances();
})();
