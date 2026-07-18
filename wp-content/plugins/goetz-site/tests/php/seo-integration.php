<?php

if (! defined('ABSPATH')) {
    fwrite(STDERR, "seo-integration.php must run through WP-CLI.\n");
    exit(1);
}

$goetz_seo_environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : '';
$goetz_seo_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
$goetz_seo_host = is_string($goetz_seo_host) ? strtolower(trim($goetz_seo_host, '[]')) : '';
$goetz_seo_loopback = $goetz_seo_host === 'localhost'
    || $goetz_seo_host === '::1'
    || preg_match('/^127(?:\.\d{1,3}){3}$/', $goetz_seo_host) === 1;
if (! $goetz_seo_loopback
    || ! in_array($goetz_seo_environment, ['local', 'development', 'test'], true)
    || getenv('GOETZ_ALLOW_MUTATING_TESTS') !== '1') {
    fwrite(STDERR, "Refusing mutating SEO integration checks outside an explicit loopback local/test environment.\n");
    exit(1);
}

use Goetz\Site\SEO\Schema;
use Goetz\Site\SEO\Yoast_Configurator;
use Goetz\Site\Settings\Site_Settings;

/**
 * @param mixed $condition
 */
function goetz_seo_integration_assert($condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array{exists: bool, option_value: string, autoload: string}
 */
function goetz_seo_snapshot_raw_option(string $name): array
{
    global $wpdb;

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $name
        ),
        ARRAY_A
    );

    return is_array($row)
        ? [
            'exists'       => true,
            'option_value' => (string) $row['option_value'],
            'autoload'     => (string) $row['autoload'],
        ]
        : ['exists' => false, 'option_value' => '', 'autoload' => 'no'];
}

/**
 * @param array{exists: bool, option_value: string, autoload: string} $snapshot
 */
function goetz_seo_restore_raw_option(string $name, array $snapshot): void
{
    global $wpdb;

    if (! $snapshot['exists']) {
        $wpdb->delete($wpdb->options, ['option_name' => $name], ['%s']);
    } else {
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
                $name
            )
        );
        if ($exists === 1) {
            $updated = $wpdb->update(
                $wpdb->options,
                [
                    'option_value' => $snapshot['option_value'],
                    'autoload'     => $snapshot['autoload'],
                ],
                ['option_name' => $name],
                ['%s', '%s'],
                ['%s']
            );
            goetz_seo_integration_assert($updated !== false, 'Could not restore an original SEO option row.');
        } else {
            $inserted = $wpdb->insert(
                $wpdb->options,
                [
                    'option_name'  => $name,
                    'option_value' => $snapshot['option_value'],
                    'autoload'     => $snapshot['autoload'],
                ],
                ['%s', '%s', '%s']
            );
            goetz_seo_integration_assert($inserted === 1, 'Could not recreate an original SEO option row.');
        }
    }

    wp_cache_delete($name, 'options');
    wp_cache_delete('alloptions', 'options');
}

/**
 * Directly write a fixture array without inviting Yoast's whole-array validator
 * to normalize unmanaged OAuth or verification values.
 *
 * @param array<string, mixed> $value
 */
function goetz_seo_write_raw_option_array(string $name, array $value): void
{
    global $wpdb;

    $updated = $wpdb->update(
        $wpdb->options,
        ['option_value' => maybe_serialize($value)],
        ['option_name' => $name],
        ['%s'],
        ['%s']
    );
    goetz_seo_integration_assert($updated !== false, 'Could not write an isolated SEO option fixture.');
    wp_cache_delete($name, 'options');
    wp_cache_delete('alloptions', 'options');
    WPSEO_Options::clear_cache();
}

/**
 * @param array<int, string> $managed_keys
 */
function goetz_seo_unmanaged_option_fingerprint(string $group, array $managed_keys): string
{
    $value = get_option($group, []);
    goetz_seo_integration_assert(is_array($value), 'A Yoast option group is not an array.');
    foreach ($managed_keys as $key) {
        unset($value[$key]);
    }

    return serialize($value);
}

/**
 * @param array<int, string> $managed_keys
 */
function goetz_seo_unmanaged_meta_fingerprint(int $post_id, array $managed_keys): string
{
    global $wpdb;

    if ($managed_keys === []) {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC",
                $post_id
            ),
            ARRAY_A
        );
    } else {
        $placeholders = implode(', ', array_fill(0, count($managed_keys), '%s'));
        $query = $wpdb->prepare(
            "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key NOT IN ({$placeholders}) ORDER BY meta_id ASC",
            array_merge([$post_id], $managed_keys)
        );
        $rows = $wpdb->get_results($query, ARRAY_A);
    }

    return serialize(is_array($rows) ? $rows : []);
}

/**
 * @param array<int, string> $keys
 * @return array<string, array<int, array{meta_id: int, meta_value: string}>>
 */
function goetz_seo_snapshot_managed_meta(int $post_id, array $keys): array
{
    global $wpdb;

    $snapshot = [];
    foreach ($keys as $key) {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
                $post_id,
                $key
            ),
            ARRAY_A
        );
        $snapshot[$key] = array_map(
            static fn(array $row): array => [
                'meta_id'    => (int) $row['meta_id'],
                'meta_value' => (string) $row['meta_value'],
            ],
            is_array($rows) ? $rows : []
        );
    }

    return $snapshot;
}

/**
 * @param array<string, array<int, array{meta_id: int, meta_value: string}>> $snapshot
 */
function goetz_seo_restore_managed_meta(int $post_id, array $snapshot): void
{
    global $wpdb;

    foreach ($snapshot as $key => $rows) {
        $wpdb->delete($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => $key], ['%d', '%s']);
        foreach ($rows as $row) {
            $inserted = $wpdb->insert(
                $wpdb->postmeta,
                [
                    'meta_id'    => $row['meta_id'],
                    'post_id'    => $post_id,
                    'meta_key'   => $key,
                    'meta_value' => $row['meta_value'],
                ],
                ['%d', '%d', '%s', '%s']
            );
            goetz_seo_integration_assert($inserted === 1, 'Could not restore original page metadata.');
        }
    }
    wp_cache_delete($post_id, 'post_meta');
}

goetz_seo_integration_assert(class_exists(Yoast_Configurator::class), 'The Yoast configurator is missing.');
goetz_seo_integration_assert(class_exists(Schema::class), 'The SEO schema runtime is missing.');
goetz_seo_integration_assert(class_exists('WPSEO_Options'), 'Yoast option APIs are unavailable.');
goetz_seo_integration_assert(defined('WPSEO_VERSION') && WPSEO_VERSION === '28.0', 'Yoast 28.0 is required.');

$config_file = GOETZ_SITE_PATH . 'config/seo-pages.php';
$page_config = is_readable($config_file) ? require $config_file : null;
goetz_seo_integration_assert(is_array($page_config) && count($page_config) === 7, 'The exact SEO page configuration is missing.');

$page_ids = [];
foreach (array_keys($page_config) as $slug) {
    $page = get_page_by_path($slug, OBJECT, 'page');
    goetz_seo_integration_assert(
        $page instanceof WP_Post
            && $page->post_type === 'page'
            && $page->post_name === $slug
            && $page->post_status === 'publish',
        'A required published SEO page is missing or invalid.'
    );
    $page_ids[$slug] = (int) $page->ID;
}

$logo_id = (int) get_theme_mod('custom_logo', 0);
$social_image_id = (int) Site_Settings::get('social_image_id', 0);
$social_metadata = wp_get_attachment_metadata($social_image_id);
goetz_seo_integration_assert($logo_id > 0 && wp_attachment_is_image($logo_id), 'A valid custom logo is required.');
goetz_seo_integration_assert(
    $social_image_id > 0
        && wp_attachment_is_image($social_image_id)
        && is_array($social_metadata)
        && (int) ($social_metadata['width'] ?? 0) === 1200
        && (int) ($social_metadata['height'] ?? 0) === 630,
    'The exact 1200x630 social image is required.'
);

$configurator = new Yoast_Configurator();
$desired_options = $configurator->desired_option_values($logo_id, $social_image_id);
$managed_option_keys = array_map('array_keys', $desired_options);
$managed_meta_keys = [
    '_yoast_wpseo_title',
    '_yoast_wpseo_metadesc',
    '_yoast_wpseo_canonical',
    '_yoast_wpseo_opengraph-image',
    '_yoast_wpseo_opengraph-image-id',
    '_yoast_wpseo_twitter-image',
    '_yoast_wpseo_twitter-image-id',
];
$option_names = array_merge(array_keys($desired_options), [Yoast_Configurator::VERSION_OPTION, Site_Settings::OPTION_NAME]);
$option_snapshots = [];
foreach ($option_names as $option_name) {
    $option_snapshots[$option_name] = goetz_seo_snapshot_raw_option($option_name);
}
$meta_snapshots = [];
$unmanaged_meta_before = [];
foreach ($page_ids as $slug => $page_id) {
    $meta_snapshots[$page_id] = goetz_seo_snapshot_managed_meta($page_id, $managed_meta_keys);
    $unmanaged_meta_before[$page_id] = goetz_seo_unmanaged_meta_fingerprint($page_id, $managed_meta_keys);
}

try {
    $opaque = hash('sha256', wp_generate_uuid4());
    foreach ($desired_options as $group => $values) {
        $fixture = get_option($group, []);
        goetz_seo_integration_assert(is_array($fixture), 'A Yoast option fixture group is invalid.');
        foreach ($values as $key => $value) {
            $fixture[$key] = is_bool($value)
                ? ! $value
                : (is_int($value) ? $value + 9000 : 'goetz-integration-mismatch');
        }
        if ($group === 'wpseo') {
            $fixture['googleverify'] = 'goetz-' . $opaque;
            $fixture['myyoast-oauth'] = [
                'config'        => ['clientId' => $opaque, 'secret' => strrev($opaque)],
                'access_tokens' => [['access_token' => hash('sha256', $opaque . 'access')]],
            ];
            $fixture['myyoast_oauth'] = ['compatibility_probe' => hash('sha256', $opaque . 'underscore')];
            $fixture['semrush_tokens'] = [['access_token' => hash('sha256', $opaque . 'semrush')]];
            $fixture['wincher_tokens'] = [['access_token' => hash('sha256', $opaque . 'wincher')]];
        }
        if ($group === 'wpseo_social') {
            $fixture['pinterestverify'] = hash('sha256', $opaque . 'pinterest');
            $fixture['other_social_urls'] = ['https://example.test/' . substr($opaque, 0, 12)];
        }
        goetz_seo_write_raw_option_array($group, $fixture);
    }

    foreach ($page_ids as $slug => $page_id) {
        update_post_meta($page_id, '_yoast_wpseo_title', 'Stale SEO title');
        update_post_meta($page_id, '_yoast_wpseo_metadesc', 'Stale SEO description');
        update_post_meta($page_id, '_yoast_wpseo_canonical', 'http://localhost.invalid/' . $slug . '/');
        update_post_meta($page_id, '_yoast_wpseo_opengraph-image', 'http://localhost.invalid/social.jpg');
        update_post_meta($page_id, '_yoast_wpseo_opengraph-image-id', '999999');
        update_post_meta($page_id, '_yoast_wpseo_twitter-image', 'http://localhost.invalid/social.jpg');
        update_post_meta($page_id, '_yoast_wpseo_twitter-image-id', '999999');
    }
    delete_option(Yoast_Configurator::VERSION_OPTION);
    WPSEO_Options::clear_cache();

    $unmanaged_options_before = [];
    foreach ($desired_options as $group => $values) {
        $unmanaged_options_before[$group] = goetz_seo_unmanaged_option_fingerprint($group, array_keys($values));
    }
    $dry_snapshot = [];
    foreach (array_keys($desired_options) as $group) {
        $dry_snapshot['option:' . $group] = goetz_seo_snapshot_raw_option($group);
    }
    $dry_snapshot['version'] = goetz_seo_snapshot_raw_option(Yoast_Configurator::VERSION_OPTION);
    foreach ($page_ids as $slug => $page_id) {
        $dry_snapshot['meta:' . $slug] = goetz_seo_snapshot_managed_meta($page_id, $managed_meta_keys);
    }

    $dry = $configurator->configure(true);
    goetz_seo_integration_assert(($dry['status'] ?? '') === 'dry-run', 'SEO dry-run status changed.');
    goetz_seo_integration_assert(($dry['changed_options'] ?? 0) === 30, 'SEO dry-run option count changed.');
    goetz_seo_integration_assert(($dry['changed_pages'] ?? 0) === 7, 'SEO dry-run page count changed.');
    goetz_seo_integration_assert(($dry['changed_meta'] ?? 0) === 49, 'SEO dry-run metadata count changed.');
    goetz_seo_integration_assert(($dry['changed_version'] ?? 0) === 1, 'SEO dry-run version count changed.');

    $after_dry = [];
    foreach (array_keys($desired_options) as $group) {
        $after_dry['option:' . $group] = goetz_seo_snapshot_raw_option($group);
    }
    $after_dry['version'] = goetz_seo_snapshot_raw_option(Yoast_Configurator::VERSION_OPTION);
    foreach ($page_ids as $slug => $page_id) {
        $after_dry['meta:' . $slug] = goetz_seo_snapshot_managed_meta($page_id, $managed_meta_keys);
    }
    goetz_seo_integration_assert($after_dry === $dry_snapshot, 'SEO dry-run wrote database state.');

    $first = $configurator->configure(false);
    goetz_seo_integration_assert(($first['status'] ?? '') === 'configured', 'First SEO apply did not configure.');
    goetz_seo_integration_assert(($first['changed_options'] ?? 0) === 30, 'First SEO apply option count changed.');
    goetz_seo_integration_assert(($first['changed_pages'] ?? 0) === 7, 'First SEO apply page count changed.');
    goetz_seo_integration_assert(($first['changed_meta'] ?? 0) === 49, 'First SEO apply metadata count changed.');
    goetz_seo_integration_assert(
        get_option(Yoast_Configurator::VERSION_OPTION, null) === Yoast_Configurator::SCHEMA_VERSION,
        'The SEO schema version was not the final successful state.'
    );

    foreach ($desired_options as $group => $values) {
        foreach ($values as $key => $value) {
            goetz_seo_integration_assert(
                WPSEO_Options::get($key, null, [$group]) === $value,
                'An approved Yoast option did not match its exact desired value.'
            );
        }
        goetz_seo_integration_assert(
            goetz_seo_unmanaged_option_fingerprint($group, array_keys($values)) === $unmanaged_options_before[$group],
            'An unmanaged verification, integration, OAuth, or token option changed.'
        );
    }

    $social_url = wp_get_attachment_url($social_image_id);
    goetz_seo_integration_assert(is_string($social_url) && $social_url !== '', 'The social image URL is unavailable.');
    foreach ($page_ids as $slug => $page_id) {
        goetz_seo_integration_assert(get_post_meta($page_id, '_yoast_wpseo_title', true) === $page_config[$slug]['title'], 'A page SEO title changed.');
        goetz_seo_integration_assert(get_post_meta($page_id, '_yoast_wpseo_metadesc', true) === $page_config[$slug]['description'], 'A page SEO description changed.');
        goetz_seo_integration_assert(! metadata_exists('post', $page_id, '_yoast_wpseo_canonical'), 'A stored page canonical survived configuration.');
        goetz_seo_integration_assert(get_post_meta($page_id, '_yoast_wpseo_opengraph-image', true) === $social_url, 'A page Open Graph image URL changed.');
        goetz_seo_integration_assert(get_post_meta($page_id, '_yoast_wpseo_opengraph-image-id', true) === (string) $social_image_id, 'A page Open Graph image ID changed.');
        goetz_seo_integration_assert(get_post_meta($page_id, '_yoast_wpseo_twitter-image', true) === $social_url, 'A page Twitter image URL changed.');
        goetz_seo_integration_assert(get_post_meta($page_id, '_yoast_wpseo_twitter-image-id', true) === (string) $social_image_id, 'A page Twitter image ID changed.');
        goetz_seo_integration_assert(
            goetz_seo_unmanaged_meta_fingerprint($page_id, $managed_meta_keys) === $unmanaged_meta_before[$page_id],
            'SEO configuration deleted or changed unmanaged page metadata.'
        );
    }

    $second = $configurator->configure(false);
    goetz_seo_integration_assert(($second['status'] ?? '') === 'noop', 'Second SEO apply was not a no-op.');
    goetz_seo_integration_assert(($second['changed_options'] ?? -1) === 0, 'Second SEO apply changed options.');
    goetz_seo_integration_assert(($second['changed_pages'] ?? -1) === 0, 'Second SEO apply changed pages.');
    goetz_seo_integration_assert(($second['changed_meta'] ?? -1) === 0, 'Second SEO apply changed metadata.');
    goetz_seo_integration_assert(($second['changed_version'] ?? -1) === 0, 'Second SEO apply changed the version.');

    $settings = Site_Settings::all();
    $settings['business_name'] = 'Dynamic Integration Firm';
    $settings['phone_e164'] = '+12395550199';
    update_option(Site_Settings::OPTION_NAME, $settings, false);
    $dynamic = $configurator->configure(false);
    goetz_seo_integration_assert(($dynamic['status'] ?? '') === 'configured', 'A current version bypassed dynamic Site Settings changes.');
    goetz_seo_integration_assert(($dynamic['changed_options'] ?? 0) >= 3, 'Dynamic Site Settings did not refresh Yoast identity options.');
    goetz_seo_integration_assert(($dynamic['changed_pages'] ?? -1) === 0, 'Dynamic Site Settings unexpectedly rewrote pages.');
    goetz_seo_integration_assert(($dynamic['changed_version'] ?? -1) === 0, 'Dynamic Site Settings rewrote the current version.');

    $base_piece = [
        '@type' => 'Organization',
        '@id'   => home_url('/#organization'),
        'logo'  => ['@id' => home_url('/#logo')],
        'image' => ['@id' => home_url('/#logo')],
        'sameAs' => ['https://example.test/profile'],
        'mainEntityOfPage' => ['@id' => home_url('/#webpage')],
    ];
    $schema = Schema::filter_organization($base_piece);
    goetz_seo_integration_assert(($schema['@type'] ?? null) === ['Organization', 'LegalService'], 'LegalService schema types changed.');
    foreach (['@id', 'logo', 'image', 'sameAs', 'mainEntityOfPage'] as $preserved_key) {
        goetz_seo_integration_assert(($schema[$preserved_key] ?? null) === $base_piece[$preserved_key], 'Yoast graph identity or links were not preserved.');
    }
    goetz_seo_integration_assert(($schema['telephone'] ?? '') === '+12395550199', 'LegalService schema did not use dynamic E.164 settings.');
    goetz_seo_integration_assert(Schema::exclude_post_type(true, 'page') === false, 'The page sitemap was excluded.');
    goetz_seo_integration_assert(Schema::exclude_post_type(false, 'post') === true, 'A non-page post type remained in sitemaps.');
    goetz_seo_integration_assert(Schema::exclude_taxonomy(false, 'category') === true, 'A taxonomy remained in sitemaps.');
} finally {
    foreach ($meta_snapshots as $post_id => $snapshot) {
        goetz_seo_restore_managed_meta((int) $post_id, $snapshot);
    }
    foreach ($option_snapshots as $option_name => $snapshot) {
        goetz_seo_restore_raw_option($option_name, $snapshot);
    }
    WPSEO_Options::clear_cache();

    foreach ($option_snapshots as $option_name => $snapshot) {
        goetz_seo_integration_assert(
            goetz_seo_snapshot_raw_option($option_name) === $snapshot,
            'An original SEO integration option row was not restored exactly.'
        );
    }
    foreach ($page_ids as $page_id) {
        goetz_seo_integration_assert(
            goetz_seo_snapshot_managed_meta($page_id, $managed_meta_keys) === $meta_snapshots[$page_id],
            'Original page SEO metadata was not restored exactly.'
        );
        goetz_seo_integration_assert(
            goetz_seo_unmanaged_meta_fingerprint($page_id, $managed_meta_keys) === $unmanaged_meta_before[$page_id],
            'Unmanaged page metadata changed during integration restoration.'
        );
    }
}

WP_CLI::success('Yoast allowlist, exact page metadata, preservation, idempotency, dynamic schema, sitemap, and restoration checks passed.');
