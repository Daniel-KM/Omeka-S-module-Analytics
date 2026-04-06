<?php declare(strict_types=1);

/**
 * Bootstrap for unit tests only (no database required).
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('Analytics\\', dirname(__DIR__) . '/src');
$loader->addPsr4('AnalyticsTest\\', __DIR__ . '/AnalyticsTest');
$loader->register();

error_reporting(E_ALL);
ini_set('display_errors', '1');
