<?php

if (! defined('ABSPATH')) {
    fwrite(STDERR, "site-settings.php must run through WP-CLI.\n");
    exit(1);
}

use Goetz\Site\Settings\Settings_Page;
use Goetz\Site\Settings\Site_Settings;

/**
 * @param mixed $condition
 */
function goetz_site_settings_integration_assert($condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

final class Goetz_Site_Settings_Test_Wp_Die extends RuntimeException
{
    public int $response;

    public function __construct(int $response)
    {
        parent::__construct('Settings page access denied.');
        $this->response = $response;
    }
}

goetz_site_settings_integration_assert(class_exists(Site_Settings::class), 'The Site_Settings runtime is missing.');
goetz_site_settings_integration_assert(class_exists(Settings_Page::class), 'The Settings_Page runtime is missing.');
goetz_site_settings_integration_assert(function_exists('goetz_site_get_setting'), 'The global plugin settings adapter is missing.');

$missing_marker = '__goetz_site_settings_missing_' . wp_generate_uuid4();
$original_option = get_option(Site_Settings::OPTION_NAME, $missing_marker);
$original_user_id = get_current_user_id();
$attachment_id = 0;
$uploaded_file = '';
$subscriber_id = 0;
$temporary_admin_id = 0;

try {
    $administrators = get_users([
        'role'   => 'administrator',
        'number' => 1,
        'fields' => 'ids',
    ]);
    if (empty($administrators[0])) {
        $temporary_admin_result = wp_insert_user([
            'user_login' => 'goetz-admin-' . substr(wp_generate_uuid4(), 0, 20),
            'user_pass'  => wp_generate_password(24, true, true),
            'user_email' => 'goetz-admin-' . wp_generate_uuid4() . '@example.test',
            'role'       => 'administrator',
        ]);
        goetz_site_settings_integration_assert(! is_wp_error($temporary_admin_result), 'Could not create the temporary administrator.');
        $temporary_admin_id = (int) $temporary_admin_result;
        goetz_site_settings_integration_assert($temporary_admin_id > 0, 'Could not create the temporary administrator.');
        $administrators = [$temporary_admin_id];
    }
    wp_set_current_user((int) $administrators[0]);

    Settings_Page::register();
    global $wp_registered_settings;
    $registration = $wp_registered_settings[Site_Settings::OPTION_NAME] ?? null;
    goetz_site_settings_integration_assert(is_array($registration), 'The Settings API option is not registered.');
    goetz_site_settings_integration_assert($registration['group'] === Site_Settings::OPTION_GROUP, 'The Settings API option group changed.');
    goetz_site_settings_integration_assert($registration['type'] === 'array', 'The Settings API option type changed.');
    goetz_site_settings_integration_assert($registration['sanitize_callback'] === [Site_Settings::class, 'sanitize'], 'The Settings API sanitizer changed.');
    goetz_site_settings_integration_assert($registration['default'] === Site_Settings::defaults(), 'The Settings API defaults changed.');

    do_action('admin_menu');
    global $submenu;
    $settings_menu = array_values(array_filter(
        $submenu['options-general.php'] ?? [],
        static fn(array $item): bool => ($item[2] ?? null) === 'goetz-site-settings'
    ));
    goetz_site_settings_integration_assert(count($settings_menu) === 1, 'The Site Settings options page slug is missing.');
    goetz_site_settings_integration_assert(($settings_menu[0][1] ?? null) === 'manage_options', 'The Site Settings page capability changed.');

    $image_bytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z8GQAAAAASUVORK5CYII=',
        true
    );
    goetz_site_settings_integration_assert(is_string($image_bytes), 'Could not decode the temporary image fixture.');
    $upload = wp_upload_bits('goetz-settings-integration.png', null, $image_bytes);
    goetz_site_settings_integration_assert(empty($upload['error']), 'Could not write the temporary image fixture.');
    $uploaded_file = (string) $upload['file'];
    $attachment_result = wp_insert_attachment([
        'post_title'     => 'Goetz settings integration image',
        'post_status'    => 'inherit',
        'post_mime_type' => 'image/png',
    ], $uploaded_file);
    goetz_site_settings_integration_assert(! is_wp_error($attachment_result), 'Could not create the temporary image attachment.');
    $attachment_id = (int) $attachment_result;
    goetz_site_settings_integration_assert($attachment_id > 0, 'Could not create the temporary image attachment.');
    update_attached_file($attachment_id, $uploaded_file);
    goetz_site_settings_integration_assert(wp_attachment_is_image($attachment_id), 'The temporary attachment is not a valid WordPress image.');

    $submitted = [
        'business_name'          => ' <b>Integration Firm</b> ',
        'alternate_name'         => ' <em>Integration and Firm</em> ',
        'phone_display'          => ' <strong>(239) 555-0188</strong> ',
        'phone_e164'             => ' +12395550188 ',
        'email'                  => ' integration@example.test ',
        'street_address'         => ' <b>100 Main St</b> ',
        'locality'               => ' <b>Fort Myers</b> ',
        'region'                 => ' <b>FL</b> ',
        'postal_code'            => ' <b>33901</b> ',
        'country_code'           => ' <b>US</b> ',
        'location_label'         => ' <b>Integration Location</b> ',
        'cta_label'              => ' <b>Integration Consultation</b> ',
        'cta_url'                => ' /integration-contact/ ',
        'footer_disclaimer'      => '<p>Integration <strong>disclaimer</strong><script>alert(1)</script></p>',
        'footer_legal_copy'      => '<p>Integration <em>legal copy</em><iframe src="https://evil.example"></iframe></p>',
        'copyright_start_year'   => '2010',
        'copyright_text'         => ' <b>Integration Rights</b> ',
        'copyright_dynamic_year' => '0',
        'social_image_id'        => (string) $attachment_id,
        'unknown'                => 'must-not-escape',
    ];
    $sanitized = Site_Settings::sanitize($submitted);
    goetz_site_settings_integration_assert(array_keys($sanitized) === array_keys(Site_Settings::defaults()), 'The strict 19-key schema changed.');
    goetz_site_settings_integration_assert(count($sanitized) === 19 && ! isset($sanitized['unknown']), 'Unknown settings escaped the schema.');
    goetz_site_settings_integration_assert($sanitized['business_name'] === 'Integration Firm', 'Text sanitization failed.');
    goetz_site_settings_integration_assert($sanitized['email'] === 'integration@example.test', 'Email sanitization failed.');
    goetz_site_settings_integration_assert($sanitized['phone_e164'] === '+12395550188', 'E.164 sanitization failed.');
    goetz_site_settings_integration_assert($sanitized['cta_url'] === '/integration-contact/', 'CTA URL sanitization failed.');
    goetz_site_settings_integration_assert(strpos($sanitized['footer_disclaimer'], '<script') === false, 'Footer disclaimer KSES failed.');
    goetz_site_settings_integration_assert(strpos($sanitized['footer_legal_copy'], '<iframe') === false, 'Footer legal-copy KSES failed.');
    goetz_site_settings_integration_assert($sanitized['copyright_start_year'] === 2010, 'Copyright year sanitization failed.');
    goetz_site_settings_integration_assert($sanitized['copyright_dynamic_year'] === false, 'Explicit boolean conversion failed.');
    goetz_site_settings_integration_assert($sanitized['social_image_id'] === $attachment_id, 'Image attachment validation failed.');

    update_option(Site_Settings::OPTION_NAME, $sanitized, false);
    $partial = Site_Settings::sanitize([
        'phone_display'          => '(239) 555-0199',
        'email'                  => 'invalid-email',
        'phone_e164'             => '+012345678',
        'cta_url'                => 'javascript:alert(1)',
        'copyright_start_year'   => '0',
        'copyright_dynamic_year' => 'not-a-boolean',
        'social_image_id'        => PHP_INT_MAX,
    ]);
    foreach ($sanitized as $key => $value) {
        if ($key === 'phone_display') {
            goetz_site_settings_integration_assert($partial[$key] === '(239) 555-0199', 'The valid partial update was not retained.');
            continue;
        }
        goetz_site_settings_integration_assert($partial[$key] === $value, "Partial/invalid input reset {$key}.");
    }

    update_option(Site_Settings::OPTION_NAME, array_merge($sanitized, [
        'business_name' => '\"><script>alert(1)</script>',
    ]), false);
    ob_start();
    Settings_Page::render();
    $settings_html = (string) ob_get_clean();
    goetz_site_settings_integration_assert(strpos($settings_html, 'action="options.php"') !== false, 'The Settings API form action is missing.');
    goetz_site_settings_integration_assert(strpos($settings_html, 'name="_wpnonce"') !== false, 'The Settings API nonce is missing.');
    goetz_site_settings_integration_assert(strpos($settings_html, '<script>alert(1)</script>') === false, 'A settings field was not escaped.');
    goetz_site_settings_integration_assert(strpos($settings_html, 'name="goetz_site_settings[social_image_id]"') !== false, 'The image attachment ID control is missing.');
    $expected_preview = wp_get_attachment_image((int) $attachment_id, 'thumbnail', false);
    goetz_site_settings_integration_assert(
        $expected_preview !== '' && strpos($settings_html, $expected_preview) !== false,
        'The WordPress-generated image preview is missing.'
    );

    wp_dequeue_script('goetz-site-settings-media');
    Settings_Page::enqueue('dashboard_page_goetz-site-settings');
    goetz_site_settings_integration_assert(! wp_script_is('goetz-site-settings-media', 'enqueued'), 'Settings media loaded on an unrelated admin screen.');
    Settings_Page::enqueue('settings_page_goetz-site-settings');
    goetz_site_settings_integration_assert(wp_script_is('goetz-site-settings-media', 'enqueued'), 'Settings media did not load on its settings screen.');

    $subscriber_result = wp_insert_user([
        'user_login' => 'goetz-sub-' . wp_generate_uuid4(),
        'user_pass'  => wp_generate_password(24, true, true),
        'user_email' => 'goetz-settings-' . wp_generate_uuid4() . '@example.test',
        'role'       => 'subscriber',
    ]);
    goetz_site_settings_integration_assert(! is_wp_error($subscriber_result), 'Could not create the temporary subscriber.');
    $subscriber_id = (int) $subscriber_result;
    goetz_site_settings_integration_assert($subscriber_id > 0, 'Could not create the temporary subscriber.');
    wp_set_current_user((int) $subscriber_id);
    $die_handler = static function ($message, $title = '', $args = []): void {
        $response = is_array($args) ? (int) ($args['response'] ?? 500) : 500;
        throw new Goetz_Site_Settings_Test_Wp_Die($response);
    };
    add_filter('wp_die_handler', static fn() => $die_handler);
    try {
        Settings_Page::render();
        throw new RuntimeException('A subscriber rendered the Site Settings page.');
    } catch (Goetz_Site_Settings_Test_Wp_Die $exception) {
        goetz_site_settings_integration_assert($exception->response === 403, 'Denied settings access did not use HTTP 403.');
    } finally {
        remove_all_filters('wp_die_handler');
        wp_set_current_user((int) $administrators[0]);
    }

    update_option(Site_Settings::OPTION_NAME, array_merge($sanitized, [
        'cta_label' => 'Settings Fallback Label',
        'cta_url'   => '/settings-fallback/',
    ]), false);
    $empty_cta = render_block([
        'blockName'    => 'goetz/cta',
        'attrs'        => ['buttonText' => '', 'buttonUrl' => ''],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]);
    goetz_site_settings_integration_assert(strpos($empty_cta, 'Settings Fallback Label') !== false, 'An empty CTA label did not use Site Settings.');
    goetz_site_settings_integration_assert(strpos($empty_cta, 'href="/settings-fallback/"') !== false, 'An empty CTA URL did not use Site Settings.');

    $explicit_cta = render_block([
        'blockName'    => 'goetz/cta',
        'attrs'        => ['buttonText' => 'Explicit CTA', 'buttonUrl' => '/explicit-cta/'],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]);
    goetz_site_settings_integration_assert(strpos($explicit_cta, 'Explicit CTA') !== false, 'An explicit CTA label was overwritten.');
    goetz_site_settings_integration_assert(strpos($explicit_cta, 'href="/explicit-cta/"') !== false, 'An explicit CTA URL was overwritten.');

    goetz_site_settings_integration_assert(goetz_site_get_setting('business_name') === 'Integration Firm', 'The plugin adapter did not return sanitized settings.');
    if (function_exists('goetz_legal_setting')) {
        goetz_site_settings_integration_assert(goetz_legal_setting('business_name') === 'Integration Firm', 'The theme adapter did not delegate to the plugin.');
        goetz_site_settings_integration_assert(goetz_legal_formatted_address() === '100 Main St, Fort Myers, FL 33901', 'The theme formatted address changed.');
        goetz_site_settings_integration_assert(
            goetz_legal_map_url() === 'https://www.google.com/maps/search/?api=1&query=100%20Main%20St%2C%20Fort%20Myers%2C%20FL%2033901',
            'The map URL is not derived from the sanitized address.'
        );
    }
} finally {
    wp_set_current_user($original_user_id);
    if ($subscriber_id > 0) {
        wp_delete_user((int) $subscriber_id);
    }
    if ($temporary_admin_id > 0) {
        wp_delete_user((int) $temporary_admin_id);
    }
    if ($attachment_id > 0) {
        wp_delete_attachment((int) $attachment_id, true);
    }
    if ($uploaded_file !== '' && is_file($uploaded_file)) {
        unlink($uploaded_file);
    }

    if ($original_option === $missing_marker) {
        delete_option(Site_Settings::OPTION_NAME);
        goetz_site_settings_integration_assert(
            get_option(Site_Settings::OPTION_NAME, $missing_marker) === $missing_marker,
            'The originally absent settings option was not deleted during restoration.'
        );
    } else {
        remove_filter(
            'sanitize_option_' . Site_Settings::OPTION_NAME,
            [Site_Settings::class, 'sanitize']
        );
        update_option(Site_Settings::OPTION_NAME, $original_option, false);
        goetz_site_settings_integration_assert(
            get_option(Site_Settings::OPTION_NAME, $missing_marker) === $original_option,
            'The exact original settings option was not restored.'
        );
    }
}

WP_CLI::success('Site Settings registration, security, sanitization, consumers, CTA fallback, and restoration passed.');
