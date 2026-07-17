<?php

declare(strict_types=1);

/**
 * Keep a fallback Site Health warning available when the required site plugin
 * is inactive and therefore cannot register its own diagnostics.
 *
 * @param array<string, mixed> $tests
 * @return array<string, mixed>
 */
function goetz_legal_add_site_status_tests(array $tests): array
{
    $tests['direct']['goetz_site_plugin_runtime'] = [
        'label' => __('Goetz Site plugin runtime', 'goetz-legal'),
        'test'  => 'goetz_legal_site_plugin_runtime_test',
    ];

    return $tests;
}

/**
 * @return array<string, mixed>
 */
function goetz_legal_site_plugin_runtime_test(): array
{
    $ready = did_action('goetz_site_loaded') > 0;

    return [
        'label'       => $ready
            ? __('The required Goetz Site plugin is running', 'goetz-legal')
            : __('The required Goetz Site plugin is not running', 'goetz-legal'),
        'status'      => $ready ? 'good' : 'critical',
        'badge'       => [
            'label' => __('Goetz Site', 'goetz-legal'),
            'color' => $ready ? 'blue' : 'red',
        ],
        'description' => '<p>' . ($ready
            ? __('The stable Gutenberg block runtime is available.', 'goetz-legal')
            : __('Activate the required Goetz Site plugin to restore the stable Gutenberg block runtime.', 'goetz-legal')) . '</p>',
        'actions'     => '',
        'test'        => 'goetz_site_plugin_runtime',
    ];
}
