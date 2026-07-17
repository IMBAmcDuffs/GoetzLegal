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
    'goetz/attorney-card' => ['name', 'role', 'bio', 'email', 'imageUrl', 'imageAlt', 'profileUrl', 'imageId', 'profileNewTab'],
    'goetz/cta'           => ['eyebrow', 'heading', 'buttonText', 'buttonUrl', 'backgroundImageId', 'backgroundImageUrl', 'buttonNewTab'],
    'goetz/faq-list'      => ['items'],
    'goetz/hero'          => ['eyebrow', 'heading', 'content', 'imageUrl', 'imageAlt', 'buttonText', 'buttonUrl', 'imageId', 'buttonNewTab'],
    'goetz/resource-links'=> ['groups', 'imageUrl', 'imageAlt', 'imageId'],
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

$new_tab_hero = render_block([
    'blockName'    => 'goetz/hero',
    'attrs'        => [
        'heading'      => 'Safe <strong>heading</strong><script>bad()</script><a href="https://bad.example">link</a>',
        'content'      => 'Read <a href="https://example.test" target="_blank" rel="noopener" onclick="bad()">more</a><img src=x>',
        'buttonText'   => 'Open profile',
        'buttonUrl'    => 'https://example.test/profile',
        'buttonNewTab' => true,
    ],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]);
goetz_site_integration_assert(str_contains($new_tab_hero, '<strong>heading</strong>'), 'Hero heading formatting was removed.');
goetz_site_integration_assert(! str_contains($new_tab_hero, '<script') && ! str_contains($new_tab_hero, '<h1>Safe <strong>heading</strong>bad()<a'), 'Hero heading allowlist is too broad.');
goetz_site_integration_assert(str_contains($new_tab_hero, 'href="https://example.test" target="_blank" rel="noopener"'), 'Hero content safe link formatting was removed.');
goetz_site_integration_assert(! str_contains($new_tab_hero, 'onclick=') && ! str_contains($new_tab_hero, '<img'), 'Hero content allowlist is too broad.');
goetz_site_integration_assert(
    str_contains($new_tab_hero, 'href="https://example.test/profile" target="_blank" rel="noopener noreferrer"'),
    'Hero new-tab link attributes are incomplete.'
);

$safe_attorney = render_block([
    'blockName'    => 'goetz/attorney-card',
    'attrs'        => [
        'name'          => '<strong>Plain Name</strong>',
        'bio'           => '<em>Plain biography</em>',
        'email'         => 'not-an-email',
        'profileUrl'    => '/plain-name/',
        'profileNewTab' => true,
    ],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]);
goetz_site_integration_assert(! str_contains($safe_attorney, '<strong>Plain Name</strong>') && ! str_contains($safe_attorney, '<em>Plain biography</em>'), 'Attorney plain-text fields allow markup.');
goetz_site_integration_assert(! str_contains($safe_attorney, 'mailto:'), 'Attorney renderer emitted an invalid email link.');
goetz_site_integration_assert(
    str_contains($safe_attorney, 'href="/plain-name/" target="_blank" rel="noopener noreferrer"'),
    'Attorney profile new-tab link attributes are incomplete.'
);

$safe_cta = render_block([
    'blockName'    => 'goetz/cta',
    'attrs'        => [
        'heading'      => 'Call <em>today</em><a href="https://bad.example">bad</a>',
        'buttonText'   => 'Contact',
        'buttonUrl'    => '/contact/',
        'buttonNewTab' => true,
    ],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]);
goetz_site_integration_assert(str_contains($safe_cta, 'Call <em>today</em>bad'), 'CTA heading allowlist removed safe formatting.');
goetz_site_integration_assert(! str_contains($safe_cta, '<a href="https://bad.example"'), 'CTA heading allowlist is too broad.');
goetz_site_integration_assert(
    str_contains($safe_cta, 'href="/contact/" target="_blank" rel="noopener noreferrer"'),
    'CTA new-tab link attributes are incomplete.'
);

$safe_faq = render_block([
    'blockName'    => 'goetz/faq-list',
    'attrs'        => [
        'items' => [
            'not-an-item',
            [
                'question' => '<strong>Plain question?</strong>',
                'answer'   => 'A <em>formatted</em> <a href="https://example.test" onclick="bad()">answer</a><img src=x>',
            ],
        ],
    ],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]);
goetz_site_integration_assert(! str_contains($safe_faq, '<strong>Plain question?</strong>'), 'FAQ question is not plain text.');
goetz_site_integration_assert(str_contains($safe_faq, '<em>formatted</em>'), 'FAQ answer formatting was removed.');
goetz_site_integration_assert(! str_contains($safe_faq, 'onclick=') && ! str_contains($safe_faq, '<img'), 'FAQ answer allowlist is too broad.');

$resource_target_states = render_block([
    'blockName'    => 'goetz/resource-links',
    'attrs'        => [
        'groups' => [
            'bad-group',
            [
                'heading' => '<em>Plain Resources</em>',
                'links'   => [
                    ['label' => 'Legacy link', 'url' => 'https://example.test/legacy'],
                    ['label' => 'Same-tab link', 'url' => 'https://example.test/same', 'newTab' => false],
                    ['label' => 'New-tab link', 'url' => 'https://example.test/new', 'newTab' => true],
                ],
            ],
        ],
    ],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]);
goetz_site_integration_assert(! str_contains($resource_target_states, '<em>Plain Resources</em>'), 'Resource heading is not plain text.');
goetz_site_integration_assert(
    preg_match('/href="https:\/\/example\.test\/legacy" target="_blank" rel="noopener noreferrer"/', $resource_target_states) === 1,
    'Legacy Resource link did not preserve new-tab behavior.'
);
goetz_site_integration_assert(
    preg_match('/href="https:\/\/example\.test\/same"(?![^>]*target=)[^>]*>/', $resource_target_states) === 1,
    'Explicit same-tab Resource link emitted a target.'
);
goetz_site_integration_assert(
    preg_match('/href="https:\/\/example\.test\/new" target="_blank" rel="noopener noreferrer"/', $resource_target_states) === 1,
    'Explicit new-tab Resource link attributes are incomplete.'
);

$upload = wp_upload_bits(
    'goetz-stable-block-' . wp_generate_uuid4() . '.png',
    null,
    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true)
);
goetz_site_integration_assert(empty($upload['error']), 'Could not create the temporary block image fixture.');
$attachment_id = wp_insert_attachment([
    'post_mime_type' => 'image/png',
    'post_title'     => 'Goetz stable block fixture',
    'post_status'    => 'inherit',
], $upload['file']);
if (is_wp_error($attachment_id) || $attachment_id < 1) {
    wp_delete_file($upload['file']);
}
goetz_site_integration_assert(! is_wp_error($attachment_id) && $attachment_id > 0, 'Could not create the temporary image attachment.');

try {
    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));
    $attachment_url = wp_get_attachment_url($attachment_id);
    goetz_site_integration_assert(is_string($attachment_url) && $attachment_url !== '', 'Temporary attachment URL is unavailable.');

    $hero_attachment = render_block([
        'blockName'    => 'goetz/hero',
        'attrs'        => [
            'imageId'  => $attachment_id,
            'imageUrl' => 'https://example.test/ignored-hero.jpg',
            'imageAlt' => 'Attachment-first hero',
        ],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]);
    goetz_site_integration_assert(str_contains($hero_attachment, esc_url($attachment_url)), 'Hero did not prefer its image attachment ID.');
    goetz_site_integration_assert(! str_contains($hero_attachment, 'ignored-hero.jpg'), 'Hero did not suppress its URL fallback for a valid attachment.');
    goetz_site_integration_assert(str_contains($hero_attachment, 'width="1"') && str_contains($hero_attachment, 'height="1"') && str_contains($hero_attachment, 'loading="eager"'), 'Hero attachment output lacks intrinsic dimensions or eager loading.');

    $attorney_attachment = render_block([
        'blockName'    => 'goetz/attorney-card',
        'attrs'        => [
            'name'     => 'Attachment Attorney',
            'imageId'  => $attachment_id,
            'imageUrl' => 'https://example.test/ignored-attorney.jpg',
            'imageAlt' => '',
        ],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]);
    goetz_site_integration_assert(str_contains($attorney_attachment, esc_url($attachment_url)), 'Attorney did not prefer its image attachment ID.');
    goetz_site_integration_assert(str_contains($attorney_attachment, 'alt="Attachment Attorney"'), 'Attorney name-as-alt compatibility changed.');

    $cta_attachment = render_block([
        'blockName'    => 'goetz/cta',
        'attrs'        => [
            'backgroundImageId'  => $attachment_id,
            'backgroundImageUrl' => 'https://example.test/ignored-cta.jpg',
        ],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]);
    goetz_site_integration_assert(str_contains($cta_attachment, esc_url($attachment_url)), 'CTA did not prefer its background image attachment ID.');
    goetz_site_integration_assert(! str_contains($cta_attachment, 'ignored-cta.jpg'), 'CTA did not suppress its URL fallback for a valid attachment.');

    $resource_attachment = render_block([
        'blockName'    => 'goetz/resource-links',
        'attrs'        => [
            'imageId'  => $attachment_id,
            'imageUrl' => 'https://example.test/ignored-resources.jpg',
            'imageAlt' => 'Informative resources',
        ],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]);
    goetz_site_integration_assert(str_contains($resource_attachment, esc_url($attachment_url)), 'Resource Links did not prefer its image attachment ID.');
    goetz_site_integration_assert(! str_contains($resource_attachment, 'aria-hidden="true"'), 'Resource Links informative image is hidden from assistive technology.');

    $invalid_attachment_fallback = render_block([
        'blockName'    => 'goetz/hero',
        'attrs'        => [
            'imageId'  => PHP_INT_MAX,
            'imageUrl' => 'https://example.test/hero-fallback.jpg',
            'imageAlt' => 'Fallback hero',
        ],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]);
    goetz_site_integration_assert(str_contains($invalid_attachment_fallback, 'https://example.test/hero-fallback.jpg'), 'Invalid attachment ID did not fall back to the stored Hero URL.');
} finally {
    wp_delete_attachment($attachment_id, true);
}

WP_CLI::success('Stable goetz-site blocks register and render independently of the theme.');
