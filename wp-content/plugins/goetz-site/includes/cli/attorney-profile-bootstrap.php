<?php

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('WP_CLI') || ! WP_CLI || ! class_exists('WP_CLI')) {
    throw new RuntimeException('The attorney profile bootstrap requires WP-CLI.');
}

$slug = getenv('GOETZ_ATTORNEY_SLUG');
$mode = getenv('GOETZ_ATTORNEY_MODE');
$slug = is_string($slug) ? $slug : '';
$mode = is_string($mode) ? $mode : '';

if (! in_array($slug, ['james-l-goetz', 'gregory-w-goetz'], true)) {
    \WP_CLI::error('Invalid attorney profile slug.');
}

if (! in_array($mode, ['plan', 'apply', 'verify'], true)) {
    \WP_CLI::error('Invalid attorney profile mode.');
}

if (! class_exists(\Goetz\Site\Attorney_Profiles::class, false)) {
    $plugin_file = dirname(__DIR__, 2) . '/goetz-site.php';
    if (! is_file($plugin_file) || is_link($plugin_file)) {
        \WP_CLI::error('The goetz-site bootstrap file is missing or redirected.');
    }

    require_once $plugin_file;
}

if (! class_exists(\Goetz\Site\Attorney_Profiles::class, false)) {
    \WP_CLI::error('The goetz-site attorney profile runtime did not load.');
}

$arguments = ['slug' => $slug];
if ($mode === 'apply') {
    $arguments['apply'] = true;
} elseif ($mode === 'verify') {
    $arguments['verify'] = true;
}

\Goetz\Site\Attorney_Profiles::cli([], $arguments);
