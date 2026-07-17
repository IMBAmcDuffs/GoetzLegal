<?php
/**
 * Plugin Name: Goetz Site
 * Description: Required site runtime and stable Gutenberg blocks for Goetz & Goetz.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Text Domain: goetz-site
 */

declare(strict_types=1);

namespace Goetz\Site;

if (! defined('ABSPATH')) {
    exit;
}

define('GOETZ_SITE_FILE', __FILE__);
define('GOETZ_SITE_PATH', plugin_dir_path(__FILE__));
define('GOETZ_SITE_URL', plugin_dir_url(__FILE__));

require_once GOETZ_SITE_PATH . 'includes/class-blocks.php';
require_once GOETZ_SITE_PATH . 'includes/functions.php';
require_once GOETZ_SITE_PATH . 'includes/class-plugin.php';

Plugin::boot();
