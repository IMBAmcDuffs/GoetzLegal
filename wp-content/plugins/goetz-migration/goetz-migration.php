<?php
/**
 * Plugin Name: Goetz Legal Migration Tool
 * Plugin URI: https://goetzlegal.com
 * Description: Scrapes the existing Goetz Legal website and generates WordPress posts/pages from the scraped data.
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

    // Handle form submissions
    if (isset($_POST['goetz_migration_action']) && check_admin_referer('goetz_migration_nonce')) {
        $action = sanitize_text_field(wp_unslash($_POST['goetz_migration_action']));

        if ($action === 'scrape') {
            $url = isset($_POST['source_url']) ? esc_url_raw(wp_unslash($_POST['source_url'])) : 'https://goetzlegal.com';
            $results = $scraper->scrape_site($url);
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
                __('Scraping complete. Found %d pages.', 'goetz-migration'),
                count($results)
            )) . '</p></div>';
        } elseif ($action === 'generate') {
            $generated = $scraper->generate_posts();
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
                __('Generated %d posts/pages successfully.', 'goetz-migration'),
                $generated
            )) . '</p></div>';
        }
    }

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Goetz Legal Migration Tool', 'goetz-migration'); ?></h1>
        <p><?php esc_html_e('This tool scrapes the existing Goetz Legal website and generates WordPress content from the data.', 'goetz-migration'); ?></p>

        <div class="card" style="max-width: 600px; padding: 20px;">
            <h2><?php esc_html_e('Step 1: Scrape Website', 'goetz-migration'); ?></h2>
            <p><?php esc_html_e('Enter the source website URL to scrape content from.', 'goetz-migration'); ?></p>
            <form method="post">
                <?php wp_nonce_field('goetz_migration_nonce'); ?>
                <input type="hidden" name="goetz_migration_action" value="scrape">
                <table class="form-table">
                    <tr>
                        <th><label for="source_url"><?php esc_html_e('Source URL', 'goetz-migration'); ?></label></th>
                        <td><input type="url" id="source_url" name="source_url" value="https://goetzlegal.com" class="regular-text"></td>
                    </tr>
                </table>
                <?php submit_button(__('Scrape Website', 'goetz-migration'), 'primary', 'submit', true); ?>
            </form>
        </div>

        <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
            <h2><?php esc_html_e('Step 2: Generate Posts', 'goetz-migration'); ?></h2>
            <p><?php esc_html_e('Generate WordPress posts and pages from the scraped data.', 'goetz-migration'); ?></p>
            <form method="post">
                <?php wp_nonce_field('goetz_migration_nonce'); ?>
                <input type="hidden" name="goetz_migration_action" value="generate">
                <?php submit_button(__('Generate Posts', 'goetz-migration'), 'secondary', 'submit', true); ?>
            </form>
        </div>

        <?php
        $scraped_data = get_option('goetz_migration_scraped_data', []);
        if (!empty($scraped_data)): ?>
            <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
                <h2><?php esc_html_e('Scraped Data Preview', 'goetz-migration'); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Title', 'goetz-migration'); ?></th>
                            <th><?php esc_html_e('URL', 'goetz-migration'); ?></th>
                            <th><?php esc_html_e('Type', 'goetz-migration'); ?></th>
                            <th><?php esc_html_e('Content Length', 'goetz-migration'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scraped_data as $page): ?>
                            <tr>
                                <td><?php echo esc_html($page['title'] ?? 'Untitled'); ?></td>
                                <td><?php echo esc_html($page['url'] ?? ''); ?></td>
                                <td><?php echo esc_html($page['type'] ?? 'page'); ?></td>
                                <td><?php echo esc_html(strlen($page['content'] ?? '')); ?> <?php esc_html_e('chars', 'goetz-migration'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
