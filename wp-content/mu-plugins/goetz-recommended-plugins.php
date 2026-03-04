<?php
/**
 * Must-Use Plugin: Recommended Plugins Checker
 *
 * Displays an admin notice listing required plugins that are not yet installed
 * or activated. This is a guidance tool — it does not auto-install plugins.
 *
 * @package GoetzLegal
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * List of recommended plugins for the Goetz Legal site.
 *
 * Each entry contains:
 *   - name:        Display name
 *   - slug:        Plugin directory slug (used for install link)
 *   - file:        Main plugin file path relative to plugins directory
 *   - required:    Whether the plugin is required (true) or recommended (false)
 *   - description: Short reason the plugin is needed
 *
 * @return array<int, array{name: string, slug: string, file: string, required: bool, description: string}>
 */
function goetz_legal_recommended_plugins(): array
{
    return [
        [
            'name'        => 'Yoast SEO',
            'slug'        => 'wordpress-seo',
            'file'        => 'wordpress-seo/wp-seo.php',
            'required'    => true,
            'description' => 'On-page SEO, XML sitemaps, meta tags, and schema markup.',
        ],
        [
            'name'        => 'Wordfence Security',
            'slug'        => 'wordfence',
            'file'        => 'wordfence/wordfence.php',
            'required'    => true,
            'description' => 'Firewall, malware scanner, login security, and real-time threat defense.',
        ],
        [
            'name'        => 'WPForms Lite',
            'slug'        => 'wpforms-lite',
            'file'        => 'wpforms-lite/wpforms.php',
            'required'    => true,
            'description' => 'Contact forms, appointment requests, and emergency intake forms.',
        ],
        [
            'name'        => 'Safe SVG',
            'slug'        => 'safe-svg',
            'file'        => 'safe-svg/safe-svg.php',
            'required'    => false,
            'description' => 'Allows safe SVG uploads for logos and icons.',
        ],
        [
            'name'        => 'WP Super Cache',
            'slug'        => 'wp-super-cache',
            'file'        => 'wp-super-cache/wp-cache.php',
            'required'    => true,
            'description' => 'Static page caching for faster page loads and lower server overhead.',
        ],
        [
            'name'        => 'Redirection',
            'slug'        => 'redirection',
            'file'        => 'redirection/redirection.php',
            'required'    => false,
            'description' => 'Manage 301 redirects and monitor 404 errors during migration.',
        ],
        [
            'name'        => 'UpdraftPlus Backup',
            'slug'        => 'updraftplus',
            'file'        => 'updraftplus/updraftplus.php',
            'required'    => false,
            'description' => 'Automated backups to cloud storage for disaster recovery.',
        ],
    ];
}

/**
 * Display an admin notice for missing required/recommended plugins.
 */
function goetz_legal_plugin_check_notice(): void
{
    if (!current_user_can('install_plugins')) {
        return;
    }

    $plugins  = goetz_legal_recommended_plugins();
    $missing_required    = [];
    $missing_recommended = [];

    foreach ($plugins as $plugin) {
        if (!is_plugin_active($plugin['file'])) {
            $install_url = wp_nonce_url(
                admin_url('plugin-install.php?tab=plugin-information&plugin=' . $plugin['slug']),
                'install-plugin_' . $plugin['slug']
            );
            $entry = sprintf(
                '<strong><a href="%s">%s</a></strong> — %s',
                esc_url(admin_url('plugin-install.php?tab=plugin-information&plugin=' . $plugin['slug'] . '&TB_iframe=true&width=772&height=600')),
                esc_html($plugin['name']),
                esc_html($plugin['description'])
            );

            if ($plugin['required']) {
                $missing_required[] = $entry;
            } else {
                $missing_recommended[] = $entry;
            }
        }
    }

    $allowed_html = [
        'strong' => [],
        'a'      => ['href' => [], 'class' => []],
    ];

    if (!empty($missing_required)) {
        echo '<div class="notice notice-error"><p><strong>' . esc_html__('Goetz Legal — Required Plugins Missing:', 'goetz-legal') . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
        foreach ($missing_required as $item) {
            echo '<li>' . wp_kses($item, $allowed_html) . '</li>';
        }
        echo '</ul></div>';
    }

    if (!empty($missing_recommended)) {
        echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__('Goetz Legal — Recommended Plugins:', 'goetz-legal') . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
        foreach ($missing_recommended as $item) {
            echo '<li>' . wp_kses($item, $allowed_html) . '</li>';
        }
        echo '</ul></div>';
    }
}
add_action('admin_notices', 'goetz_legal_plugin_check_notice');
