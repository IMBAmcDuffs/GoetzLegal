<?php

declare(strict_types=1);

namespace Goetz\Site;

const STABLE_BLOCK_NAMES = [
    'goetz/attorney-card',
    'goetz/cta',
    'goetz/faq-list',
    'goetz/hero',
    'goetz/resource-links',
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
