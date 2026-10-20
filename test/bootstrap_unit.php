<?php
/**
 * Lightweight bootstrap for unit tests.
 *
 * Sets up autoloading for the Search Assistant AI module classes without
 * requiring a database connection or full Omeka bootstrap.
 */

// Omeka defines this in its own bootstrap; module config files use it.
if (!defined('OMEKA_PATH')) {
    define('OMEKA_PATH', dirname(dirname(dirname(__DIR__))));
}

// Load Composer autoloader
require dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php';

// Register PSR-4 autoloader for the Search Assistant AI module
spl_autoload_register(function ($class) {
    $prefix = 'SearchAssistantAI\\';
    $baseDir = dirname(__DIR__) . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Enable error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', 1);
