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
    'goetz/practice-area-item' => ['label'],
    'goetz/practice-areas'=> ['heading', 'backgroundImageId', 'backgroundImageUrl', 'backgroundImageAlt', 'scaleImageId', 'scaleImageUrl', 'scaleImageAlt'],
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

$welcome_attribute_names = [
    'leftImageId',
    'leftImageUrl',
    'leftImageAlt',
    'rightImageId',
    'rightImageUrl',
    'rightImageAlt',
    'heading',
    'contentPrefix',
    'phoneLabel',
    'phoneUrl',
    'contentJoin',
    'onlineLabel',
    'onlineUrl',
];
$welcome_type = $registry->get_registered('goetz/welcome');
goetz_site_integration_assert($welcome_type instanceof WP_Block_Type, 'Missing plugin block registration: goetz/welcome');
$welcome_registered_attribute_names = array_keys($welcome_type->attributes ?? []);
$welcome_stable_attribute_names = array_values(array_intersect(
    $welcome_registered_attribute_names,
    $welcome_attribute_names
));
$welcome_unexpected_attribute_names = array_diff(
    $welcome_registered_attribute_names,
    $welcome_attribute_names,
    ['lock', 'metadata', 'className']
);
goetz_site_integration_assert(
    $welcome_stable_attribute_names === $welcome_attribute_names
        && $welcome_unexpected_attribute_names === [],
    'Saved attribute schema changed for goetz/welcome.'
);
goetz_site_integration_assert(
    in_array('goetz-site-block-editor', $welcome_type->editor_script_handles, true),
    'Shared editor handle is missing for goetz/welcome.'
);
goetz_site_integration_assert(
    ($welcome_type->supports['html'] ?? null) === false,
    'Custom HTML must remain disabled for goetz/welcome.'
);
goetz_site_integration_assert(
    $welcome_type->view_script_handles === [],
    'Welcome must remain fully visible without a frontend script.'
);

$practice_type = $registry->get_registered('goetz/practice-areas');
$practice_item_type = $registry->get_registered('goetz/practice-area-item');
goetz_site_integration_assert(
    $practice_type instanceof WP_Block_Type && $practice_item_type instanceof WP_Block_Type,
    'Practice Areas parent and child must both register from metadata.'
);
goetz_site_integration_assert(
    ($practice_type->provides_context ?? []) === [
        'goetz/scaleImageId'  => 'scaleImageId',
        'goetz/scaleImageUrl' => 'scaleImageUrl',
        'goetz/scaleImageAlt' => 'scaleImageAlt',
    ],
    'Practice Areas parent context changed.'
);
goetz_site_integration_assert(
    ($practice_item_type->parent ?? []) === ['goetz/practice-areas']
        && ($practice_item_type->uses_context ?? []) === [
            'goetz/scaleImageId',
            'goetz/scaleImageUrl',
            'goetz/scaleImageAlt',
        ],
    'Practice Area child ownership/context changed.'
);
goetz_site_integration_assert(
    ($practice_item_type->supports['inserter'] ?? null) === false,
    'Practice Area child must not be available in the free-standing inserter.'
);
$practice_view_handles = array_values(array_filter(
    $practice_type->view_script_handles,
    static fn(string $handle): bool => str_contains($handle, 'practice-areas')
));
goetz_site_integration_assert(
    count($practice_view_handles) === 1,
    'Practice Areas must register exactly one frontend animation script.'
);
$editor_data = wp_scripts()->get_data('goetz-site-block-editor', 'data');
$localized_settings_match = [];
goetz_site_integration_assert(
    is_string($editor_data)
        && preg_match('/var goetzSiteEditorSettings = (\{.*\});/', $editor_data, $localized_settings_match) === 1,
    'The editor bundle is missing its public Welcome fallback settings.'
);
$localized_settings = json_decode($localized_settings_match[1], true);
goetz_site_integration_assert(
    $localized_settings === [
        'phoneLabel' => (string) goetz_site_get_setting('phone_display', '(239) 936-2841'),
        'phoneUrl'   => 'tel:' . (string) goetz_site_get_setting('phone_e164', '+12399362841'),
        'onlineUrl'  => '/contact/',
    ],
    'The editor bundle localized more than the effective public Welcome fallbacks.'
);

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

$welcome_defaults = render_block([
    'blockName'    => 'goetz/welcome',
    'attrs'        => [],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]);
goetz_site_integration_assert(substr_count($welcome_defaults, '<section') === 1, 'Welcome must render exactly one section.');
goetz_site_integration_assert(substr_count($welcome_defaults, '<h2') === 1, 'Welcome must render exactly one H2.');
goetz_site_integration_assert(substr_count($welcome_defaults, '<a ') === 2, 'Welcome must render exactly two links.');
foreach (['goetz-welcome', 'goetz-intro-section', 'goetz-intro', '<strong>Mr. Goetz welcomes</strong>', '(239) 936-2841', 'href="tel:+12399362841"', 'href="/contact/"', '>online</a>', 'law-scale-icon-purple.png', 'alt=""', 'aria-hidden="true"'] as $needle) {
    goetz_site_integration_assert(str_contains($welcome_defaults, $needle), "Welcome default output changed: {$needle}");
}
goetz_site_integration_assert(
    ! str_contains($welcome_defaults, 'https://goetzlegal.com'),
    'Welcome default output embeds the legacy source origin.'
);
goetz_site_integration_assert(
    is_readable(WP_CONTENT_DIR . '/plugins/goetz-site/assets/seed/law-scale-icon-purple.png'),
    'Welcome decorative scale asset is not tracked by the site plugin.'
);
goetz_site_integration_assert(
    hash_file('sha256', WP_CONTENT_DIR . '/plugins/goetz-site/assets/seed/law-scale-icon-purple.png') === '53eade44aed3bacbb4bc00665c43811395ba91a1f1699f338c9e0a07a017cfd0',
    'Welcome decorative scale asset hash changed.'
);

$site_settings_filter = static fn() => [
    'phone_display' => '(239) 555-0188',
    'phone_e164'    => '+12395550188',
    'cta_url'       => '/custom-contact/',
];
add_filter('pre_option_goetz_site_settings', $site_settings_filter);
try {
    $welcome_settings_fallback = render_block([
        'blockName'    => 'goetz/welcome',
        'attrs'        => [
            'phoneLabel' => '',
            'phoneUrl'   => '',
            'onlineUrl'  => '',
        ],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]);
    $welcome_phone_label_override = render_block([
        'blockName'    => 'goetz/welcome',
        'attrs'        => [
            'phoneLabel' => 'Direct office line',
            'phoneUrl'   => '',
        ],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]);
    $welcome_phone_url_override = render_block([
        'blockName'    => 'goetz/welcome',
        'attrs'        => [
            'phoneLabel' => '',
            'phoneUrl'   => '+12395550199',
        ],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]);
    $welcome_online_label_override = render_block([
        'blockName'    => 'goetz/welcome',
        'attrs'        => [
            'onlineLabel' => 'through our secure form',
            'onlineUrl'   => '',
        ],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]);
    $welcome_online_url_override = render_block([
        'blockName'    => 'goetz/welcome',
        'attrs'        => [
            'onlineUrl' => '/alternate-contact/',
        ],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]);
} finally {
    remove_filter('pre_option_goetz_site_settings', $site_settings_filter);
}
foreach (['(239) 555-0188', 'href="tel:+12395550188"', 'href="/contact/"'] as $needle) {
    goetz_site_integration_assert(str_contains($welcome_settings_fallback, $needle), "Welcome Site Settings fallback changed: {$needle}");
}
goetz_site_integration_assert(
    ! str_contains($welcome_settings_fallback, 'href="/custom-contact/"'),
    'Welcome empty online URL must not inherit the general Site Settings CTA URL.'
);
goetz_site_integration_assert(
    str_contains($welcome_phone_label_override, '>Direct office line</a>')
        && str_contains($welcome_phone_label_override, 'href="tel:+12395550188"'),
    'Welcome phone label override did not retain the Site Settings phone URL fallback.'
);
goetz_site_integration_assert(
    str_contains($welcome_phone_url_override, '>(239) 555-0188</a>')
        && str_contains($welcome_phone_url_override, 'href="tel:+12395550199"'),
    'Welcome phone URL override did not retain the Site Settings phone label fallback.'
);
goetz_site_integration_assert(
    str_contains($welcome_online_label_override, '>through our secure form</a>')
        && str_contains($welcome_online_label_override, 'href="/contact/"'),
    'Welcome online label override did not retain the exact contact URL fallback.'
);
goetz_site_integration_assert(
    str_contains($welcome_online_url_override, '>online</a>')
        && str_contains($welcome_online_url_override, 'href="/alternate-contact/"'),
    'Welcome online URL override did not retain the default online label.'
);

$welcome_overrides = render_block([
    'blockName'    => 'goetz/welcome',
    'attrs'        => [
        'leftImageUrl' => 'https://example.test/welcome-left.jpg',
        'leftImageAlt' => 'Left meaningful image',
        'rightImageUrl'=> 'https://example.test/welcome-right.jpg',
        'rightImageAlt'=> 'Right meaningful image',
        'heading'      => '<strong>Safe</strong> <em>heading</em><script>bad()</script><a href="https://bad.example">bad link</a>',
        'contentPrefix'=> '<strong>Plain prefix</strong>',
        'phoneLabel'   => '<em>Custom phone</em>',
        'phoneUrl'     => '+12395550199',
        'contentJoin'  => '<b>Plain join</b>',
        'onlineLabel'  => '<span>Plain online</span>',
        'onlineUrl'    => 'https://example.test/contact',
    ],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]);
foreach (['https://example.test/welcome-left.jpg', 'Left meaningful image', 'https://example.test/welcome-right.jpg', 'Right meaningful image', '<strong>Safe</strong> <em>heading</em>bad()bad link', 'href="tel:+12395550199"', 'href="https://example.test/contact"'] as $needle) {
    goetz_site_integration_assert(str_contains($welcome_overrides, $needle), "Welcome explicit override changed: {$needle}");
}
goetz_site_integration_assert(! str_contains($welcome_overrides, '<script'), 'Welcome heading allowlist is too broad.');
goetz_site_integration_assert(! str_contains($welcome_overrides, '<h2><strong>Safe</strong> <em>heading</em>bad()<a'), 'Welcome heading allows links.');
foreach (['<strong>Plain prefix</strong>', '<em>Custom phone</em>', '<b>Plain join</b>', '<span>Plain online</span>'] as $unsafe_plain_text) {
    goetz_site_integration_assert(! str_contains($welcome_overrides, $unsafe_plain_text), "Welcome plain field allows markup: {$unsafe_plain_text}");
}

$welcome_rejected_urls = render_block([
    'blockName'    => 'goetz/welcome',
    'attrs'        => [
        'phoneUrl' => 'javascript:alert(1)',
        'onlineUrl'=> '//evil.example/path',
    ],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]);
goetz_site_integration_assert(! str_contains($welcome_rejected_urls, 'javascript:') && ! str_contains($welcome_rejected_urls, 'evil.example'), 'Welcome emitted a rejected URL.');
goetz_site_integration_assert(str_contains($welcome_rejected_urls, 'href="tel:+12399362841"') && str_contains($welcome_rejected_urls, 'href="/contact/"'), 'Welcome rejected URLs did not fall back safely.');

$welcome_empty_online_label = render_block([
    'blockName'    => 'goetz/welcome',
    'attrs'        => [
        'onlineLabel' => '   ',
        'onlineUrl'   => '/contact/',
    ],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]);
goetz_site_integration_assert(
    str_contains($welcome_empty_online_label, '>online</a>'),
    'Welcome empty online label must retain an accessible link name.'
);

$practice_labels = [
    'Corporate',
    'Construction',
    'Real Estate',
    'Probate',
    'Criminal',
    'Bankruptcy',
    'Appeals',
];
$practice_children = array_map(
    static fn(string $label): array => [
        'blockName'    => 'goetz/practice-area-item',
        'attrs'        => ['label' => $label],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ],
    $practice_labels
);
$practice_block = [
    'blockName'    => 'goetz/practice-areas',
    'attrs'        => [
        'heading'            => 'Providing <strong>Trusted Advice</strong> in:<script>bad()</script>',
        'backgroundImageUrl' => 'https://example.test/practice-background.jpg',
        'backgroundImageAlt' => 'Law office library',
        'scaleImageUrl'      => 'https://example.test/practice-scale.png',
        'scaleImageAlt'      => 'Scales of justice',
    ],
    'innerBlocks'  => $practice_children,
    'innerHTML'    => '',
    'innerContent' => array_fill(0, count($practice_children), null),
];
$practice_serialized = serialize_block($practice_block);
$practice_parsed = parse_blocks($practice_serialized);
goetz_site_integration_assert(
    count($practice_parsed) === 1
        && ($practice_parsed[0]['blockName'] ?? '') === 'goetz/practice-areas'
        && count($practice_parsed[0]['innerBlocks'] ?? []) === 7
        && serialize_blocks($practice_parsed) === $practice_serialized,
    'Practice Areas InnerBlocks do not survive WordPress serialization unchanged.'
);
$practice_rendered = do_blocks($practice_serialized);
goetz_site_integration_assert(substr_count($practice_rendered, '<section') === 1, 'Practice Areas must render exactly one section.');
goetz_site_integration_assert(substr_count($practice_rendered, '<h2') === 1, 'Practice Areas must render exactly one H2.');
goetz_site_integration_assert(substr_count($practice_rendered, '<ul') === 1, 'Practice Areas must render exactly one stable list wrapper.');
goetz_site_integration_assert(substr_count($practice_rendered, '<li') === 7, 'Practice Areas must render all seven serialized children exactly once.');
foreach ([
    'wp-block-goetz-practice-areas',
    'goetz-practice-areas',
    'goetz-practice-list',
    'Providing <strong>Trusted Advice</strong> in:bad()',
    'https://example.test/practice-background.jpg',
    'Law office library',
    'https://example.test/practice-scale.png',
    'Scales of justice',
] as $needle) {
    goetz_site_integration_assert(str_contains($practice_rendered, $needle), "Practice Areas output changed: {$needle}");
}
goetz_site_integration_assert(! str_contains($practice_rendered, '<script'), 'Practice Areas heading allowlist is too broad.');
foreach ($practice_labels as $label) {
    goetz_site_integration_assert(
        substr_count($practice_rendered, '>' . $label . '</b>') === 1,
        "Practice Area child was lost or duplicated: {$label}"
    );
}

$unsafe_practice_child = do_blocks(serialize_block([
    'blockName'    => 'goetz/practice-areas',
    'attrs'        => [],
    'innerBlocks'  => [[
        'blockName'    => 'goetz/practice-area-item',
        'attrs'        => ['label' => '<em>Plain label</em><script>bad()</script>'],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]],
    'innerHTML'    => '',
    'innerContent' => [null],
]));
goetz_site_integration_assert(
    ! str_contains($unsafe_practice_child, '<em>')
        && ! str_contains($unsafe_practice_child, '<script>')
        && str_contains($unsafe_practice_child, '&lt;em&gt;Plain label&lt;/em&gt;&lt;script&gt;bad()&lt;/script&gt;'),
    'Practice Area child label is not escaped as plain text.'
);

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
    'goetz-stable-block-' . wp_generate_uuid4() . '.jpg',
    null,
    file_get_contents(WP_CONTENT_DIR . '/plugins/goetz-site/assets/seed/JAMES-L-2.jpg')
);
goetz_site_integration_assert(empty($upload['error']), 'Could not create the temporary block image fixture.');
$attachment_id = wp_insert_attachment([
    'post_mime_type' => 'image/jpeg',
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
    goetz_site_integration_assert(str_contains($hero_attachment, 'width="910"') && str_contains($hero_attachment, 'height="660"') && str_contains($hero_attachment, 'loading="eager"'), 'Hero attachment output lacks intrinsic dimensions or eager loading.');

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

    $welcome_attachment = render_block([
        'blockName'    => 'goetz/welcome',
        'attrs'        => [
            'leftImageId'   => $attachment_id,
            'leftImageUrl'  => 'https://example.test/ignored-welcome-left.jpg',
            'leftImageAlt'  => 'Attachment-first left image',
            'rightImageId'  => $attachment_id,
            'rightImageUrl' => 'https://example.test/ignored-welcome-right.jpg',
            'rightImageAlt' => 'Attachment-first right image',
        ],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]);
    goetz_site_integration_assert(substr_count($welcome_attachment, esc_url($attachment_url)) >= 2, 'Welcome did not prefer both image attachment IDs.');
    goetz_site_integration_assert(! str_contains($welcome_attachment, 'ignored-welcome-left.jpg') && ! str_contains($welcome_attachment, 'ignored-welcome-right.jpg'), 'Welcome did not suppress stored URL fallbacks for valid attachments.');
    foreach (['Attachment-first left image', 'Attachment-first right image', 'width="910"', 'height="660"', 'loading="lazy"', 'decoding="async"', 'srcset="'] as $needle) {
        goetz_site_integration_assert(str_contains($welcome_attachment, $needle), "Welcome responsive attachment output changed: {$needle}");
    }
    goetz_site_integration_assert(
        preg_match('/sizes="(?:auto, )?\(min-width: 601px\) 20vw, 85vw"/', $welcome_attachment) === 1,
        'Welcome responsive attachment sizes changed.'
    );

    $practice_attachment = do_blocks(serialize_block([
        'blockName'    => 'goetz/practice-areas',
        'attrs'        => [
            'backgroundImageId'  => $attachment_id,
            'backgroundImageUrl' => 'https://example.test/ignored-practice-background.jpg',
            'backgroundImageAlt' => 'Attachment-first practice background',
            'scaleImageId'       => $attachment_id,
            'scaleImageUrl'      => 'https://example.test/ignored-practice-scale.jpg',
            'scaleImageAlt'      => 'Attachment-first practice scale',
        ],
        'innerBlocks'  => [[
            'blockName'    => 'goetz/practice-area-item',
            'attrs'        => ['label' => 'Attachment Practice'],
            'innerBlocks'  => [],
            'innerHTML'    => '',
            'innerContent' => [],
        ]],
        'innerHTML'    => '',
        'innerContent' => [null],
    ]));
    goetz_site_integration_assert(
        substr_count($practice_attachment, esc_url($attachment_url)) >= 2,
        'Practice Areas did not prefer both parent-provided image attachment IDs.'
    );
    goetz_site_integration_assert(
        ! str_contains($practice_attachment, 'ignored-practice-background.jpg')
            && ! str_contains($practice_attachment, 'ignored-practice-scale.jpg'),
        'Practice Areas did not suppress stored URL fallbacks for valid attachments.'
    );
    foreach (['Attachment-first practice background', 'Attachment-first practice scale'] as $needle) {
        goetz_site_integration_assert(str_contains($practice_attachment, $needle), "Practice Areas responsive attachment output changed: {$needle}");
    }
    goetz_site_integration_assert(
        preg_match('/sizes="(?:auto, )?36px"/', $practice_attachment) === 1,
        'Practice Areas scale attachment sizes changed.'
    );

    $welcome_invalid_attachment = render_block([
        'blockName'    => 'goetz/welcome',
        'attrs'        => [
            'leftImageId'   => PHP_INT_MAX,
            'leftImageUrl'  => 'https://example.test/welcome-left-fallback.jpg',
            'leftImageAlt'  => 'Left fallback',
            'rightImageId'  => PHP_INT_MAX,
            'rightImageUrl' => 'https://example.test/welcome-right-fallback.jpg',
            'rightImageAlt' => 'Right fallback',
        ],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]);
    foreach (['welcome-left-fallback.jpg', 'Left fallback', 'welcome-right-fallback.jpg', 'Right fallback'] as $needle) {
        goetz_site_integration_assert(str_contains($welcome_invalid_attachment, $needle), "Welcome invalid attachment fallback changed: {$needle}");
    }

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
