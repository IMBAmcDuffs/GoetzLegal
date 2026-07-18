<?php
/**
 * Plugin Name: Goetz Legal Migration Tool
 * Plugin URI: https://goetzlegal.com
 * Description: Safely discovers live Goetz Legal pages and creates missing WordPress pages without overwriting editor content.
 * Version: 1.1.0
 * Author: Goetz & Goetz
 * Text Domain: goetz-migration
 * Requires PHP: 8.0
 *
 * @package GoetzMigration
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GOETZ_MIGRATION_VERSION', '1.1.0');
define('GOETZ_MIGRATION_CONTENT_VERSION', '6');
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
 * Process one guarded admin request without exposing force-existing mode.
 *
 * @param array<string, mixed>         $request Explicit, unslashed request values.
 * @param Goetz_Migration_Scraper|null $scraper Optional deterministic test seam.
 * @return array<string, mixed>|WP_Error
 */
function goetz_migration_handle_admin_request(
    array $request,
    ?Goetz_Migration_Scraper $scraper = null
)
{
    if (! current_user_can('manage_options')) {
        return new WP_Error(
            'goetz_migration_forbidden',
            __('You are not allowed to run the migration planner.', 'goetz-migration')
        );
    }

    $nonce = isset($request['nonce']) && is_scalar($request['nonce'])
        ? sanitize_text_field((string) $request['nonce'])
        : '';
    if (! wp_verify_nonce($nonce, 'goetz_migration_nonce')) {
        return new WP_Error(
            'goetz_migration_bad_nonce',
            __('The migration request expired. Refresh the page and try again.', 'goetz-migration')
        );
    }

    foreach (['force_existing', 'force-existing', 'yes'] as $forbidden_key) {
        if (array_key_exists($forbidden_key, $request)) {
            return new WP_Error(
                'goetz_migration_force_unavailable',
                __('Existing pages cannot be forced through wp-admin.', 'goetz-migration')
            );
        }
    }

    $action = isset($request['action']) && is_scalar($request['action'])
        ? sanitize_key((string) $request['action'])
        : '';
    if (! in_array($action, ['preview', 'import'], true)) {
        return new WP_Error(
            'goetz_migration_unknown_action',
            __('Unknown migration action.', 'goetz-migration')
        );
    }

    $source_url = isset($request['source_url']) && is_scalar($request['source_url'])
        ? esc_url_raw((string) $request['source_url'])
        : 'https://goetzlegal.com';
    $scheme = wp_parse_url($source_url, PHP_URL_SCHEME);
    if ($source_url === '' || ! in_array($scheme, ['http', 'https'], true)) {
        return new WP_Error(
            'goetz_migration_bad_source',
            __('Enter a valid HTTP or HTTPS source URL.', 'goetz-migration')
        );
    }

    $scraper = $scraper ?? new Goetz_Migration_Scraper();
    $plan = $scraper->plan_site($source_url, false);

    if ($action === 'preview') {
        return ['mode' => 'dry-run', 'plan' => $plan];
    }

    return [
        'mode'   => 'apply',
        'plan'   => $plan,
        'result' => $scraper->apply_plan($plan),
    ];
}

/**
 * Render the create-only admin page.
 */
function goetz_migration_admin_page(): void
{
    if (! current_user_can('manage_options')) {
        return;
    }

    $default_source_url = 'https://goetzlegal.com';
    $response = null;

    if (isset($_POST['goetz_migration_action'])) {
        $request = [
            'action'     => is_scalar($_POST['goetz_migration_action'])
                ? sanitize_key(wp_unslash((string) $_POST['goetz_migration_action']))
                : '',
            'source_url' => isset($_POST['source_url']) && is_scalar($_POST['source_url'])
                ? esc_url_raw(wp_unslash((string) $_POST['source_url']))
                : $default_source_url,
            'nonce'      => isset($_POST['_wpnonce']) && is_scalar($_POST['_wpnonce'])
                ? sanitize_text_field(wp_unslash((string) $_POST['_wpnonce']))
                : '',
        ];
        $default_source_url = $request['source_url'];
        $response = goetz_migration_handle_admin_request($request);
    }

    if (is_wp_error($response)) {
        echo '<div class="notice notice-error"><p>' . esc_html($response->get_error_message()) . '</p></div>';
    } elseif (is_array($response) && ($response['mode'] ?? '') === 'dry-run') {
        $summary = $response['plan']['summary'] ?? [];
        echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
            /* translators: 1: planned creates, 2: existing pages skipped. */
            __('Preview complete. %1$d missing pages can be created; %2$d existing pages will be skipped.', 'goetz-migration'),
            (int) ($summary['planned_create'] ?? 0),
            (int) ($summary['skipped_existing'] ?? 0)
        )) . '</p></div>';
    } elseif (is_array($response) && ($response['mode'] ?? '') === 'apply') {
        $summary = $response['result']['summary'] ?? [];
        echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
            /* translators: 1: pages created, 2: existing pages skipped, 3: errors. */
            __('Create-missing run complete. Created %1$d, skipped %2$d, errors %3$d.', 'goetz-migration'),
            (int) ($summary['created'] ?? 0),
            (int) ($summary['skipped'] ?? 0),
            (int) ($summary['errors'] ?? 0)
        )) . '</p></div>';
    }

    $plan = is_array($response) ? ($response['plan'] ?? null) : null;
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Goetz Legal Migration Tool', 'goetz-migration'); ?></h1>
        <p><?php esc_html_e('Safely discover the seven approved source pages and create only pages that are missing. Existing editor content, templates, SEO fields, menus, and site settings remain untouched.', 'goetz-migration'); ?></p>

        <div class="card" style="max-width: 600px; padding: 20px;">
            <h2><?php esc_html_e('Step 1: Preview Create-Only Plan', 'goetz-migration'); ?></h2>
            <p><?php esc_html_e('Run read-only source discovery and review which missing pages can be created.', 'goetz-migration'); ?></p>
            <form method="post">
                <?php wp_nonce_field('goetz_migration_nonce'); ?>
                <input type="hidden" name="goetz_migration_action" value="preview">
                <table class="form-table">
                    <tr>
                        <th><label for="source_url"><?php esc_html_e('Source URL', 'goetz-migration'); ?></label></th>
                        <td><input type="url" id="source_url" name="source_url" value="<?php echo esc_attr($default_source_url); ?>" class="regular-text"></td>
                    </tr>
                </table>
                <?php submit_button(__('Preview Missing Pages', 'goetz-migration'), 'primary', 'submit', true); ?>
            </form>
        </div>

        <div class="card" style="max-width: 600px; padding: 20px; margin-top: 20px;">
            <h2><?php esc_html_e('Step 2: Create Missing Pages', 'goetz-migration'); ?></h2>
            <p><?php esc_html_e('Create only missing approved pages. Every existing page is skipped before content or media processing.', 'goetz-migration'); ?></p>
            <form method="post">
                <?php wp_nonce_field('goetz_migration_nonce'); ?>
                <input type="hidden" name="goetz_migration_action" value="import">
                <input type="hidden" name="source_url" value="<?php echo esc_attr($default_source_url); ?>">
                <?php submit_button(__('Create Missing Pages', 'goetz-migration'), 'secondary', 'submit', true); ?>
            </form>
        </div>

        <?php if (is_array($plan)): ?>
            <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
                <h2><?php esc_html_e('Plan Results', 'goetz-migration'); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Title', 'goetz-migration'); ?></th>
                            <th><?php esc_html_e('Slug', 'goetz-migration'); ?></th>
                            <th><?php esc_html_e('Status', 'goetz-migration'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($plan['items'] ?? [] as $item): ?>
                            <tr>
                                <td><?php echo esc_html($item['title'] ?? 'Untitled'); ?></td>
                                <td><?php echo esc_html($item['slug'] ?? ''); ?></td>
                                <td><?php echo esc_html($item['status'] ?? ''); ?></td>
                            </tr>
                            <?php if (! empty($item['diff'])): ?>
                                <tr><td colspan="3"><pre style="white-space: pre-wrap;"><?php echo esc_html($item['diff']); ?></pre></td></tr>
                            <?php endif; ?>
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
        private static function force_confirmation_required(bool $force_existing, bool $yes): bool
        {
            return $force_existing && ! $yes;
        }

        /**
         * @param array<string, mixed> $summary
         */
        private static function import_has_errors(array $summary): bool
        {
            return (int) ($summary['errors'] ?? 0) > 0;
        }

        /**
         * @param array<string, mixed> $plan
         */
        private function render_plan(array $plan): void
        {
            foreach ($plan['items'] ?? [] as $item) {
                WP_CLI::line(sprintf(
                    '%s: %s',
                    (string) ($item['slug'] ?? '(unknown)'),
                    (string) ($item['status'] ?? 'unknown')
                ));
                if (! empty($item['diff'])) {
                    WP_CLI::line((string) $item['diff']);
                }
            }
        }

        /**
         * @param array<string, mixed> $assoc_args
         */
        private function scraper(array $assoc_args): Goetz_Migration_Scraper
        {
            $fetch_proxy = isset($assoc_args['fetch-proxy'])
                ? esc_url_raw((string) $assoc_args['fetch-proxy'])
                : '';

            return new Goetz_Migration_Scraper($fetch_proxy);
        }

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
            $source = isset($assoc_args['source']) ? esc_url_raw((string) $assoc_args['source']) : 'https://goetzlegal.com';
            $plan = $this->scraper($assoc_args)->plan_site($source, false);
            $this->render_plan($plan);
            WP_CLI::success(sprintf(
                'Plan complete: %d missing, %d existing skipped.',
                (int) ($plan['summary']['planned_create'] ?? 0),
                (int) ($plan['summary']['skipped_existing'] ?? 0)
            ));
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
         *
         * [--dry-run]
         * : Print the normalized block plan without writing persistent state.
         *
         * [--force-existing]
         * : Explicitly plan updates to existing page content. Requires confirmation.
         *
         * [--yes]
         * : Accept the force-existing confirmation non-interactively.
         */
        public function import(array $args, array $assoc_args): void
        {
            $source = isset($assoc_args['source']) ? esc_url_raw((string) $assoc_args['source']) : 'https://goetzlegal.com';
            $dry_run = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);
            $force_existing = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'force-existing', false);
            $yes = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'yes', false);
            $scraper = $this->scraper($assoc_args);
            $plan = $scraper->plan_site($source, $force_existing);
            $this->render_plan($plan);

            if (self::force_confirmation_required($force_existing, $yes)) {
                WP_CLI::confirm('Force mode can replace existing editor page content. Continue?');
            }

            if ($dry_run) {
                WP_CLI::success('Dry-run complete; no persistent state changed.');
                return;
            }

            $result = $scraper->apply_plan($plan);
            $summary = $result['summary'] ?? [];
            $message = sprintf(
                'Created %d, updated %d, skipped %d, errors %d.',
                (int) ($summary['created'] ?? 0),
                (int) ($summary['updated'] ?? 0),
                (int) ($summary['skipped'] ?? 0),
                (int) ($summary['errors'] ?? 0)
            );

            if (self::import_has_errors($summary)) {
                WP_CLI::error($message);
                return;
            }

            WP_CLI::success($message);
        }
    }

    WP_CLI::add_command('goetz-migration', 'Goetz_Migration_CLI');
}
