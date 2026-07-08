<?php
/**
 * Plugin Name: Goetz Legal Migration Tool
 * Plugin URI: https://goetzlegal.com
 * Description: Imports the live Goetz Legal pages into the Tailpress rebuild using REST-first discovery with HTML fallback.
 * Version: 1.0.0
 * Author: Goetz & Goetz
 * Text Domain: goetz-migration
 * Requires PHP: 8.0
 *
 * @package GoetzMigration
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GOETZ_MIGRATION_VERSION', '1.0.0');
define('GOETZ_MIGRATION_CONTENT_VERSION', '5');
define('GOETZ_MIGRATION_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once GOETZ_MIGRATION_PLUGIN_DIR . 'includes/class-scraper.php';

/**
 * Register the admin menu page for the migration tool.
 */
function goetz_migration_admin_menu(): void
{
    add_management_page(
        __('Goetz Migration Tool', 'goetz-migration'),
        __('Goetz Migration', 'goetz-migration'),
        'manage_options',
        'goetz-migration',
        'goetz_migration_admin_page'
    );
}
add_action('admin_menu', 'goetz_migration_admin_menu');

/**
 * Render the admin page.
 */
function goetz_migration_admin_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $scraper = new Goetz_Migration_Scraper();
    $default_source_url = (string) get_option('goetz_migration_source_url', 'https://goetzlegal.com');
    $default_fetch_proxy_url = (string) get_option('goetz_migration_fetch_proxy_url', '');

    if (isset($_POST['goetz_migration_action']) && check_admin_referer('goetz_migration_nonce')) {
        $action = sanitize_text_field(wp_unslash($_POST['goetz_migration_action']));
        $url = isset($_POST['source_url']) ? esc_url_raw(wp_unslash($_POST['source_url'])) : 'https://goetzlegal.com';
        $fetch_proxy_url = isset($_POST['fetch_proxy_url']) ? esc_url_raw(wp_unslash($_POST['fetch_proxy_url'])) : '';
        update_option('goetz_migration_source_url', $url, false);
        update_option('goetz_migration_fetch_proxy_url', $fetch_proxy_url, false);
        $default_source_url = $url;
        $default_fetch_proxy_url = $fetch_proxy_url;

        if ($action === 'scan') {
            $results = $scraper->scan_site($url);
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
                __('Scan complete. Found %d live pages.', 'goetz-migration'),
                count($results)
            )) . '</p></div>';
        } elseif ($action === 'import') {
            $summary = $scraper->import_site($url);
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
                __('Import complete. Created %d, updated %d, skipped %d.', 'goetz-migration'),
                $summary['created'],
                $summary['updated'],
                $summary['skipped']
            )) . '</p></div>';
        }
    }

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Goetz Legal Migration Tool', 'goetz-migration'); ?></h1>
        <p><?php esc_html_e('This tool imports the approved live-site pages into the WordPress rebuild. It uses the source sitemap and REST API first, then falls back to rendered HTML if needed.', 'goetz-migration'); ?></p>

        <div class="card" style="max-width: 600px; padding: 20px;">
            <h2><?php esc_html_e('Step 1: Scan Source', 'goetz-migration'); ?></h2>
            <p><?php esc_html_e('Dry-run source discovery and preview the seven approved pages.', 'goetz-migration'); ?></p>
            <form method="post">
                <?php wp_nonce_field('goetz_migration_nonce'); ?>
                <input type="hidden" name="goetz_migration_action" value="scan">
                <table class="form-table">
                    <tr>
                        <th><label for="source_url"><?php esc_html_e('Source URL', 'goetz-migration'); ?></label></th>
                        <td><input type="url" id="source_url" name="source_url" value="<?php echo esc_attr($default_source_url); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="fetch_proxy_url"><?php esc_html_e('Fetch Proxy URL', 'goetz-migration'); ?></label></th>
                        <td>
                            <input type="url" id="fetch_proxy_url" name="fetch_proxy_url" value="<?php echo esc_attr($default_fetch_proxy_url); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Optional Cloudflare Worker-style URL. Direct fetch is attempted first.', 'goetz-migration'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Scan Source', 'goetz-migration'), 'primary', 'submit', true); ?>
            </form>
        </div>

        <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
            <h2><?php esc_html_e('Step 2: Import Pages', 'goetz-migration'); ?></h2>
            <p><?php esc_html_e('Create or update pages, sideload media, configure the homepage, and rebuild menus.', 'goetz-migration'); ?></p>
            <form method="post">
                <?php wp_nonce_field('goetz_migration_nonce'); ?>
                <input type="hidden" name="goetz_migration_action" value="import">
                <input type="hidden" name="source_url" value="<?php echo esc_attr($default_source_url); ?>">
                <input type="hidden" name="fetch_proxy_url" value="<?php echo esc_attr($default_fetch_proxy_url); ?>">
                <?php submit_button(__('Import Pages', 'goetz-migration'), 'secondary', 'submit', true); ?>
            </form>
        </div>

        <?php
        $scraped_data = get_option('goetz_migration_scan_data', []);
        if (!empty($scraped_data)): ?>
            <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
                <h2><?php esc_html_e('Scan Preview', 'goetz-migration'); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Title', 'goetz-migration'); ?></th>
                            <th><?php esc_html_e('Slug', 'goetz-migration'); ?></th>
                            <th><?php esc_html_e('Method', 'goetz-migration'); ?></th>
                            <th><?php esc_html_e('Media', 'goetz-migration'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scraped_data as $page): ?>
                            <tr>
                                <td><?php echo esc_html($page['title'] ?? 'Untitled'); ?></td>
                                <td><?php echo esc_html($page['slug'] ?? ''); ?></td>
                                <td><?php echo esc_html($page['method'] ?? ''); ?></td>
                                <td><?php echo esc_html((string) ($page['media_count'] ?? 0)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

if (defined('WP_CLI') && WP_CLI) {
    /**
     * WP-CLI integration for repeatable local imports.
     */
    class Goetz_Migration_CLI
    {
        /**
         * Dry-run scan the source site.
         *
         * ## OPTIONS
         *
         * [--source=<url>]
         * : Source site URL.
         *
         * [--fetch-proxy=<url>]
         * : Optional Cloudflare Worker-style fetch proxy URL used only after direct fetch fails.
         */
        public function scan(array $args, array $assoc_args): void
        {
            $source = isset($assoc_args['source']) ? esc_url_raw($assoc_args['source']) : 'https://goetzlegal.com';
            if (!empty($assoc_args['fetch-proxy'])) {
                update_option('goetz_migration_fetch_proxy_url', esc_url_raw($assoc_args['fetch-proxy']), false);
            }
            $pages = (new Goetz_Migration_Scraper())->scan_site($source);
            \WP_CLI\Utils\format_items('table', $pages, ['slug', 'title', 'method', 'media_count']);
            WP_CLI::success(sprintf('Found %d approved pages.', count($pages)));
        }

        /**
         * Import the approved source pages.
         *
         * ## OPTIONS
         *
         * [--source=<url>]
         * : Source site URL.
         *
         * [--fetch-proxy=<url>]
         * : Optional Cloudflare Worker-style fetch proxy URL used only after direct fetch fails.
         */
        public function import(array $args, array $assoc_args): void
        {
            $source = isset($assoc_args['source']) ? esc_url_raw($assoc_args['source']) : 'https://goetzlegal.com';
            if (!empty($assoc_args['fetch-proxy'])) {
                update_option('goetz_migration_fetch_proxy_url', esc_url_raw($assoc_args['fetch-proxy']), false);
            }
            $summary = (new Goetz_Migration_Scraper())->import_site($source);
            WP_CLI::success(sprintf(
                'Created %d, updated %d, skipped %d.',
                $summary['created'],
                $summary['updated'],
                $summary['skipped']
            ));
        }
    }

    WP_CLI::add_command('goetz-migration', 'Goetz_Migration_CLI');
}
