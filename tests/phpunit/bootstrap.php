<?php

declare(strict_types=1);

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

if (! is_readable($autoload)) {
    throw new RuntimeException(
        'Root Composer dependencies are missing. Run ./manager.sh deps:install before PHPUnit.'
    );
}

require $autoload;

if (! defined('GOETZ_TEST_ROOT')) {
    define('GOETZ_TEST_ROOT', dirname(__DIR__, 2));
}
