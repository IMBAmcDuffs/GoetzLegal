<?php
/**
 * Plugin Name: Goetz AI Authority
 * Plugin URI: https://goetzlegal.com
 * Description: Generates AI authority files (llms.txt, ai.txt, humans.txt) so AI crawlers understand and properly attribute the site. Includes an admin UI with one-click generation.
 * Version: 1.0.0
 * Author: Goetz & Goetz
 * Text Domain: goetz-ai-authority
 * Requires PHP: 8.0
 *
 * @package GoetzAIAuthority
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GOETZ_AI_AUTHORITY_VERSION', '1.0.0');
define('GOETZ_AI_AUTHORITY_DIR', plugin_dir_path(__FILE__));

require_once GOETZ_AI_AUTHORITY_DIR . 'includes/class-generator.php';

// ---------------------------------------------------------------------------
// Admin menu
// ---------------------------------------------------------------------------

/**
 * Register the admin settings page.
 */
function goetz_ai_authority_admin_menu(): void
{
    add_options_page(
        __('AI Authority Files', 'goetz-ai-authority'),
        __('AI Authority', 'goetz-ai-authority'),
        'manage_options',
        'goetz-ai-authority',
        'goetz_ai_authority_admin_page'
    );
}
add_action('admin_menu', 'goetz_ai_authority_admin_menu');

/**
 * Render the admin settings page.
 */
function goetz_ai_authority_admin_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $generator = new Goetz_AI_Authority_Generator();
    $message   = '';

    // Handle form actions
    if (isset($_POST['goetz_ai_action']) && check_admin_referer('goetz_ai_authority_nonce')) {
        $action = sanitize_text_field(wp_unslash($_POST['goetz_ai_action']));

        if ($action === 'generate_all') {
            $results = $generator->generate_all();
            $successes = array_filter($results);
            $message = sprintf(
                __('Generated %d of %d files successfully.', 'goetz-ai-authority'),
                count($successes),
                count($results)
            );
        } elseif ($action === 'generate_llms') {
            $ok = $generator->generate_llms_txt();
            $message = $ok
                ? __('llms.txt generated successfully.', 'goetz-ai-authority')
                : __('Failed to write llms.txt — check file permissions.', 'goetz-ai-authority');
        } elseif ($action === 'generate_ai') {
            $ok = $generator->generate_ai_txt();
            $message = $ok
                ? __('ai.txt generated successfully.', 'goetz-ai-authority')
                : __('Failed to write ai.txt — check file permissions.', 'goetz-ai-authority');
        } elseif ($action === 'generate_humans') {
            $ok = $generator->generate_humans_txt();
            $message = $ok
                ? __('humans.txt generated successfully.', 'goetz-ai-authority')
                : __('Failed to write humans.txt — check file permissions.', 'goetz-ai-authority');
        } elseif ($action === 'save_settings') {
            $fields = [
                'site_name',
                'site_description',
                'contact_email',
                'contact_phone',
                'address',
                'practice_areas',
                'attorneys',
            ];
            $settings = [];
            foreach ($fields as $field) {
                $key = 'goetz_ai_' . $field;
                $settings[$field] = isset($_POST[$key]) ? sanitize_textarea_field(wp_unslash($_POST[$key])) : '';
            }
            update_option('goetz_ai_authority_settings', $settings);
            $message = __('Settings saved.', 'goetz-ai-authority');
        }
    }

    $settings = wp_parse_args(get_option('goetz_ai_authority_settings', []), [
        'site_name'        => get_bloginfo('name'),
        'site_description' => get_bloginfo('description'),
        'contact_email'    => get_option('admin_email'),
        'contact_phone'    => '(239) 936-0066',
        'address'          => '1534 Broadway, Suite 201, Fort Myers, FL 33901',
        'practice_areas'   => "Corporate Law\nConstruction Law",
        'attorneys'        => "James L. Goetz — Senior Partner\nGregory W. Goetz — Partner\nDawn Heitl — Associate Attorney",
    ]);

    $root = ABSPATH;
    $files = [
        'llms.txt'   => file_exists($root . 'llms.txt')   ? filemtime($root . 'llms.txt')   : false,
        'ai.txt'     => file_exists($root . 'ai.txt')     ? filemtime($root . 'ai.txt')     : false,
        'humans.txt' => file_exists($root . 'humans.txt') ? filemtime($root . 'humans.txt') : false,
    ];

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('AI Authority Files', 'goetz-ai-authority'); ?></h1>

        <?php if ($message): ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
        <?php endif; ?>

        <p><?php esc_html_e('These files help AI crawlers (ChatGPT, Perplexity, Google AI, etc.) understand your site, properly attribute content, and respect your preferred usage policies.', 'goetz-ai-authority'); ?></p>

        <!-- Current File Status -->
        <div class="card" style="max-width:700px;padding:20px;">
            <h2><?php esc_html_e('File Status', 'goetz-ai-authority'); ?></h2>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e('File', 'goetz-ai-authority'); ?></th><th><?php esc_html_e('Status', 'goetz-ai-authority'); ?></th><th><?php esc_html_e('Last Generated', 'goetz-ai-authority'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($files as $fname => $mtime): ?>
                    <tr>
                        <td><code><?php echo esc_html($fname); ?></code></td>
                        <td><?php echo $mtime ? '<span style="color:green;">✓ ' . esc_html__('Exists', 'goetz-ai-authority') . '</span>' : '<span style="color:red;">✗ ' . esc_html__('Missing', 'goetz-ai-authority') . '</span>'; ?></td>
                        <td><?php echo $mtime ? esc_html(date_i18n('Y-m-d H:i:s', $mtime)) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top:15px;display:flex;gap:8px;flex-wrap:wrap;">
                <form method="post" style="display:inline;">
                    <?php wp_nonce_field('goetz_ai_authority_nonce'); ?>
                    <input type="hidden" name="goetz_ai_action" value="generate_all">
                    <?php submit_button(__('Generate All Files', 'goetz-ai-authority'), 'primary', 'submit', false); ?>
                </form>
                <form method="post" style="display:inline;">
                    <?php wp_nonce_field('goetz_ai_authority_nonce'); ?>
                    <input type="hidden" name="goetz_ai_action" value="generate_llms">
                    <?php submit_button(__('Generate llms.txt', 'goetz-ai-authority'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post" style="display:inline;">
                    <?php wp_nonce_field('goetz_ai_authority_nonce'); ?>
                    <input type="hidden" name="goetz_ai_action" value="generate_ai">
                    <?php submit_button(__('Generate ai.txt', 'goetz-ai-authority'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post" style="display:inline;">
                    <?php wp_nonce_field('goetz_ai_authority_nonce'); ?>
                    <input type="hidden" name="goetz_ai_action" value="generate_humans">
                    <?php submit_button(__('Generate humans.txt', 'goetz-ai-authority'), 'secondary', 'submit', false); ?>
                </form>
            </div>
        </div>

        <!-- Settings -->
        <div class="card" style="max-width:700px;padding:20px;margin-top:20px;">
            <h2><?php esc_html_e('Site Information', 'goetz-ai-authority'); ?></h2>
            <p class="description"><?php esc_html_e('This information is used when generating the AI authority files.', 'goetz-ai-authority'); ?></p>
            <form method="post">
                <?php wp_nonce_field('goetz_ai_authority_nonce'); ?>
                <input type="hidden" name="goetz_ai_action" value="save_settings">
                <table class="form-table">
                    <tr>
                        <th><label for="goetz_ai_site_name"><?php esc_html_e('Site Name', 'goetz-ai-authority'); ?></label></th>
                        <td><input type="text" id="goetz_ai_site_name" name="goetz_ai_site_name" value="<?php echo esc_attr($settings['site_name']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="goetz_ai_site_description"><?php esc_html_e('Description', 'goetz-ai-authority'); ?></label></th>
                        <td><textarea id="goetz_ai_site_description" name="goetz_ai_site_description" rows="3" class="large-text"><?php echo esc_textarea($settings['site_description']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="goetz_ai_contact_email"><?php esc_html_e('Contact Email', 'goetz-ai-authority'); ?></label></th>
                        <td><input type="email" id="goetz_ai_contact_email" name="goetz_ai_contact_email" value="<?php echo esc_attr($settings['contact_email']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="goetz_ai_contact_phone"><?php esc_html_e('Contact Phone', 'goetz-ai-authority'); ?></label></th>
                        <td><input type="text" id="goetz_ai_contact_phone" name="goetz_ai_contact_phone" value="<?php echo esc_attr($settings['contact_phone']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="goetz_ai_address"><?php esc_html_e('Address', 'goetz-ai-authority'); ?></label></th>
                        <td><input type="text" id="goetz_ai_address" name="goetz_ai_address" value="<?php echo esc_attr($settings['address']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="goetz_ai_practice_areas"><?php esc_html_e('Practice Areas (one per line)', 'goetz-ai-authority'); ?></label></th>
                        <td><textarea id="goetz_ai_practice_areas" name="goetz_ai_practice_areas" rows="4" class="large-text"><?php echo esc_textarea($settings['practice_areas']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="goetz_ai_attorneys"><?php esc_html_e('Attorneys (one per line)', 'goetz-ai-authority'); ?></label></th>
                        <td><textarea id="goetz_ai_attorneys" name="goetz_ai_attorneys" rows="4" class="large-text"><?php echo esc_textarea($settings['attorneys']); ?></textarea></td>
                    </tr>
                </table>
                <?php submit_button(__('Save Settings', 'goetz-ai-authority')); ?>
            </form>
        </div>

        <!-- Info about each file -->
        <div class="card" style="max-width:700px;padding:20px;margin-top:20px;">
            <h2><?php esc_html_e('About These Files', 'goetz-ai-authority'); ?></h2>
            <dl>
                <dt><strong>llms.txt</strong></dt>
                <dd style="margin-bottom:12px;"><?php esc_html_e('A structured file following the llms.txt standard that helps large language models (ChatGPT, Claude, etc.) understand your site content, practice areas, and how to cite your firm properly.', 'goetz-ai-authority'); ?></dd>

                <dt><strong>ai.txt</strong></dt>
                <dd style="margin-bottom:12px;"><?php esc_html_e('Declares AI usage policies — who may use your content for training, attribution requirements, and opt-out instructions. Similar in spirit to robots.txt but specifically for AI crawlers.', 'goetz-ai-authority'); ?></dd>

                <dt><strong>humans.txt</strong></dt>
                <dd style="margin-bottom:12px;"><?php esc_html_e('Identifies the humans behind the website — the team, technologies used, and contact details. Useful for transparency and attribution.', 'goetz-ai-authority'); ?></dd>
            </dl>
        </div>
    </div>
    <?php
}
