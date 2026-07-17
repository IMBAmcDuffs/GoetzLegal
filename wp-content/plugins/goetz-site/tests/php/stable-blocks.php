<?php

if (! defined('ABSPATH')) {
    fwrite(STDERR, "stable-blocks.php must run through WP-CLI.\n");
    exit(1);
}

/**
 * @param bool $condition
 */
function goetz_site_integration_assert($condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$expected = [
    'goetz/attorney-card' => ['name', 'role', 'bio', 'email', 'imageUrl', 'imageAlt', 'profileUrl'],
    'goetz/cta'           => ['eyebrow', 'heading', 'buttonText', 'buttonUrl'],
    'goetz/faq-list'      => ['items'],
    'goetz/hero'          => ['eyebrow', 'heading', 'content', 'imageUrl', 'imageAlt', 'buttonText', 'buttonUrl'],
    'goetz/resource-links'=> ['groups', 'imageUrl', 'imageAlt'],
];

goetz_site_integration_assert(
    class_exists('Goetz\\Site\\Plugin'),
    'The goetz-site plugin runtime is not booted.'
);
$boot_count = did_action('goetz_site_loaded');
Goetz\Site\Plugin::boot();
Goetz\Site\Plugin::boot();
goetz_site_integration_assert(
    did_action('goetz_site_loaded') === $boot_count,
    'The goetz-site plugin runtime booted more than once.'
);

$registry = WP_Block_Type_Registry::get_instance();
foreach ($expected as $name => $attribute_names) {
    $block_type = $registry->get_registered($name);
    goetz_site_integration_assert($block_type instanceof WP_Block_Type, "Missing plugin block registration: {$name}");
    $registered_attribute_names = array_keys($block_type->attributes ?? []);
    $stable_attribute_names = array_values(array_intersect($registered_attribute_names, $attribute_names));
    $unexpected_attribute_names = array_diff(
        $registered_attribute_names,
        $attribute_names,
        ['lock', 'metadata', 'className']
    );
    goetz_site_integration_assert(
        $stable_attribute_names === $attribute_names && $unexpected_attribute_names === [],
        "Saved attribute schema changed for {$name}"
    );
    goetz_site_integration_assert(
        in_array('goetz-site-block-editor', $block_type->editor_script_handles, true),
        "Shared editor handle is missing for {$name}"
    );
}

goetz_site_integration_assert(
    ! function_exists('goetz_legal_register_blocks'),
    'The theme still owns Gutenberg block registration.'
);
goetz_site_integration_assert(
    ! is_dir(WP_CONTENT_DIR . '/themes/goetz-legal/blocks'),
    'Stable block source still exists under the theme.'
);

$site_health_tests = apply_filters('site_status_tests', []);
goetz_site_integration_assert(
    isset($site_health_tests['direct']['goetz_site_runtime_assets']),
    'The plugin runtime/editor asset Site Health test is missing.'
);
$plugin_health = call_user_func($site_health_tests['direct']['goetz_site_runtime_assets']['test']);
goetz_site_integration_assert($plugin_health['status'] === 'good', 'The built plugin runtime is not Site Health ready.');

if (function_exists('goetz_legal_site_plugin_runtime_test')) {
    goetz_site_integration_assert(
        has_filter('site_status_tests', 'goetz_legal_add_site_status_tests') !== false,
        'The theme plugin-runtime fallback Site Health test is not registered.'
    );
    goetz_site_integration_assert(
        goetz_legal_site_plugin_runtime_test()['status'] === 'good',
        'The theme did not observe the booted plugin runtime.'
    );
}

$attorney = render_block([
    'blockName'    => 'goetz/attorney-card',
    'attrs'        => [
        'name'       => 'Jordan Example',
        'role'       => 'Trial Attorney',
        'bio'        => 'Representative legacy biography.',
        'email'      => 'jordan@example.test',
        'imageUrl'   => 'https://example.test/jordan.jpg',
        'imageAlt'   => 'Jordan Example portrait',
        'profileUrl' => '/jordan-example/',
    ],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]);
foreach (['wp-block-goetz-attorney-card', 'goetz-attorney-card', 'Jordan Example', 'Trial Attorney', 'Representative legacy biography.', 'mailto:jordan@example.test', '/jordan-example/', 'https://example.test/jordan.jpg', 'Jordan Example portrait'] as $needle) {
    goetz_site_integration_assert(str_contains($attorney, $needle), "Attorney card output changed: {$needle}");
}

$cta = render_block([
    'blockName'    => 'goetz/cta',
    'attrs'        => [
        'eyebrow'    => 'CUSTOM EYEBROW',
        'heading'    => 'READY <strong>NOW?</strong>',
        'buttonText' => 'Request Help',
        'buttonUrl'  => '/request-help/',
    ],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]);
foreach (['wp-block-goetz-cta', 'goetz-cta', 'CUSTOM EYEBROW', 'READY <strong>NOW?</strong>', 'Request Help', 'href="/request-help/"'] as $needle) {
    goetz_site_integration_assert(str_contains($cta, $needle), "CTA output changed: {$needle}");
}

$legacy_cta = render_block([
    'blockName'    => 'goetz/cta',
    'attrs'        => [],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]);
goetz_site_integration_assert(str_contains($legacy_cta, 'NEED A <b>LAWYER?</b>'), 'CTA legacy heading output changed.');
goetz_site_integration_assert(str_contains($legacy_cta, 'href="/contact/"'), 'CTA legacy button URL changed.');
if (! function_exists('goetz_legal_asset_url')) {
    goetz_site_integration_assert(
        str_contains($legacy_cta, 'https://goetzlegal.com/wp-content/uploads/2022/08/law-updates-bg.jpg'),
        'CTA legacy background fallback is missing without the theme.'
    );
}

$faq = render_block([
    'blockName'    => 'goetz/faq-list',
    'attrs'        => [
        'items' => [[
            'question' => 'A representative question?',
            'answer'   => "First answer line.\n\nSecond answer line.",
        ]],
    ],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]);
foreach (['wp-block-goetz-faq-list', 'goetz-faq-list', 'A representative question?', '<p>First answer line.</p>', '<p>Second answer line.</p>'] as $needle) {
    goetz_site_integration_assert(str_contains($faq, $needle), "FAQ output changed: {$needle}");
}

$hero = render_block([
    'blockName'    => 'goetz/hero',
    'attrs'        => [
        'eyebrow'    => 'CUSTOM HERO',
        'heading'    => 'Trusted <strong>Counsel</strong>',
        'content'    => 'Representative hero copy.',
        'imageUrl'   => 'https://example.test/hero.jpg',
        'imageAlt'   => 'Representative hero portrait',
        'buttonText' => 'Meet the Team',
        'buttonUrl'  => '/team/',
    ],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]);
foreach (['wp-block-goetz-hero', 'goetz-hero', 'CUSTOM HERO', 'Trusted <strong>Counsel</strong>', 'Representative hero copy.', 'https://example.test/hero.jpg', 'Representative hero portrait', 'Meet the Team', 'href="/team/"'] as $needle) {
    goetz_site_integration_assert(str_contains($hero, $needle), "Hero output changed: {$needle}");
}

$resource_links = render_block([
    'blockName'    => 'goetz/resource-links',
    'attrs'        => [
        'groups' => [[
            'heading' => 'Legal Resources',
            'links'   => [['label' => 'Florida Courts', 'url' => 'https://www.flcourts.gov/']],
        ]],
        'imageUrl' => 'https://example.test/resources.jpg',
        'imageAlt' => 'Representative resources image',
    ],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]);
foreach (['wp-block-goetz-resource-links', 'goetz-resource-links', '<strong>Legal</strong> Resources', 'Florida Courts', 'https://www.flcourts.gov/', 'https://example.test/resources.jpg', 'Representative resources image'] as $needle) {
    goetz_site_integration_assert(str_contains($resource_links, $needle), "Resource links output changed: {$needle}");
}
if (! function_exists('goetz_legal_asset_url')) {
    $legacy_resource_links = render_block([
        'blockName'    => 'goetz/resource-links',
        'attrs'        => [],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]);
    goetz_site_integration_assert(
        str_contains($legacy_resource_links, 'https://goetzlegal.com/wp-content/uploads/2022/08/law-firm-img.jpg'),
        'Resource links legacy image fallback is missing without the theme.'
    );
}

WP_CLI::success('Stable goetz-site blocks register and render independently of the theme.');
