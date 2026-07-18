<?php

declare(strict_types=1);

namespace Goetz\Site {

const STABLE_BLOCK_NAMES = [
    'goetz/attorney-card',
    'goetz/attorney-grid',
    'goetz/cta',
    'goetz/faq-list',
    'goetz/hero',
    'goetz/practice-area-item',
    'goetz/practice-areas',
    'goetz/resource-links',
    'goetz/welcome',
];

/**
 * @param array<string, mixed> $tests
 * @return array<string, mixed>
 */
function register_site_health_tests(array $tests): array
{
    $tests['direct']['goetz_site_runtime_assets'] = [
        'label' => __('Goetz Site runtime assets', 'goetz-site'),
        'test'  => __NAMESPACE__ . '\\site_health_test',
    ];

    return $tests;
}

/**
 * @return array<string, mixed>
 */
function site_health_test(): array
{
    $registry = \WP_Block_Type_Registry::get_instance();
    $registered_names = array_values(
        array_filter(
            STABLE_BLOCK_NAMES,
            static fn(string $name): bool => $registry->is_registered($name)
        )
    );

    return site_health_result($registered_names, editor_assets_ready());
}

/**
 * @param array<int, string> $registered_names
 * @return array<string, mixed>
 */
function site_health_result(array $registered_names, bool $editor_assets_ready): array
{
    $runtime_ready = array_diff(STABLE_BLOCK_NAMES, $registered_names) === [];
    $ready = $runtime_ready && $editor_assets_ready;

    return [
        'label'       => $ready
            ? __('Goetz Site block runtime is ready', 'goetz-site')
            : __('Goetz Site block runtime needs attention', 'goetz-site'),
        'status'      => $ready ? 'good' : 'critical',
        'badge'       => [
            'label' => __('Goetz Site', 'goetz-site'),
            'color' => $ready ? 'blue' : 'red',
        ],
        'description' => '<p>' . ($ready
            ? __('The stable block runtime and editor bundle are available.', 'goetz-site')
            : __('The required stable block runtime or editor bundle is missing. Server rendering remains available for every block that could be registered.', 'goetz-site')) . '</p>',
        'actions'     => '',
        'test'        => 'goetz_site_runtime_assets',
    ];
}

function editor_assets_ready(): bool
{
    return is_readable(GOETZ_SITE_PATH . 'build/index.js')
        && is_readable(GOETZ_SITE_PATH . 'build/index.asset.php')
        && wp_script_is(Blocks::EDITOR_HANDLE, 'registered');
}

function normalize_boolean(mixed $value, bool $fallback = false): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value === 1 ? true : ($value === 0 ? false : $fallback);
    }

    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false'], true)) {
            return false;
        }
    }

    return $fallback;
}

function valid_image_attachment_id(mixed $value): int
{
    $attachment_id = absint($value);
    if ($attachment_id < 1
        || get_post_type($attachment_id) !== 'attachment'
        || ! wp_attachment_is_image($attachment_id)) {
        return 0;
    }

    return $attachment_id;
}

function attachment_matches_managed_seed(mixed $value, string $asset_key): bool
{
    $attachment_id = valid_image_attachment_id($value);
    if ($attachment_id < 1 || $asset_key === '') {
        return false;
    }

    /** @var array<string, mixed>|null $assets */
    static $assets = null;
    if ($assets === null) {
        $config_path = GOETZ_SITE_PATH . 'config/homepage.php';
        $config = is_readable($config_path) ? require $config_path : [];
        $assets = is_array($config) && is_array($config['assets'] ?? null)
            ? $config['assets']
            : [];
    }

    $asset = $assets[$asset_key] ?? null;
    if (! is_array($asset)
        || ! is_string($asset['seed_key'] ?? null)
        || ! is_string($asset['sha256'] ?? null)
        || preg_match('/^[a-f0-9]{64}$/', $asset['sha256']) !== 1) {
        return false;
    }

    return get_post_meta(
        $attachment_id,
        Migrations\Media_Seeder::META_KEY,
        false
    ) === [$asset['seed_key']]
        && get_post_meta(
            $attachment_id,
            Migrations\Media_Seeder::CHECKSUM_META_KEY,
            false
        ) === [$asset['sha256']];
}

function heading_markup(string $value): string
{
    return wp_kses($value, [
        'strong' => [],
        'b'      => [],
        'em'     => [],
        'br'     => [],
    ]);
}

function rich_text_markup(string $value): string
{
    return wp_kses($value, [
        'strong' => [],
        'b'      => [],
        'em'     => [],
        'br'     => [],
        'a'      => [
            'href'   => true,
            'target' => true,
            'rel'    => true,
        ],
    ]);
}
}

namespace {
    function goetz_site_get_setting(string $key, mixed $fallback = null): mixed
    {
        return \Goetz\Site\Settings\Site_Settings::get($key, $fallback);
    }
}
