<?php

// WP-CLI eval-file executes fixture source after its own bootstrap statements,
// so a file-level strict_types declaration cannot legally remain first.

if (! defined('ABSPATH')) {
    fwrite(STDERR, "compat-site.php must run through WP-CLI.\n");
    exit(1);
}

/**
 * Build the independent, create-only legacy homepage shape accepted by the
 * guarded native migration. This intentionally does not call migration
 * internals: the compatibility matrix must prove the public command can
 * recognize and replace the legacy source shape on every supported core.
 *
 * @param array<string, mixed> $config
 */
function goetz_compat_legacy_homepage(array $config): string
{
    $homepage = $config['homepage'] ?? null;
    $assets = $config['assets'] ?? null;
    if (! is_array($homepage) || ! is_array($assets)) {
        throw new RuntimeException('The compatibility homepage configuration is invalid.');
    }

    $asset_url = static function (string $key, string $year_month = '2022/08') use ($assets): string {
        $asset = $assets[$key] ?? null;
        if (! is_array($asset) || ! isset($asset['filename']) || ! is_string($asset['filename'])) {
            throw new RuntimeException("The compatibility homepage asset {$key} is invalid.");
        }
        $filename = $key === 'hero_exterior'
            ? 'Goetz-Legal-Exterior-1-scaled.png'
            : $asset['filename'];

        return home_url('/wp-content/uploads/' . $year_month . '/' . $filename);
    };
    $block = static fn(string $name, array $attrs): string => '<!-- wp:' . $name . ' '
        . wp_json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' /-->';

    $hero = $homepage['hero'];
    $content = $block('goetz/hero', [
        'eyebrow'    => $hero['eyebrow'],
        'heading'    => $hero['heading'],
        'content'    => $hero['content'],
        'imageUrl'   => $asset_url('hero_exterior', '2025/03'),
        'imageAlt'   => 'Goetz Legal exterior',
        'buttonText' => $hero['buttonText'],
        'buttonUrl'  => home_url('/james-l-goetz/'),
    ]);
    $content .= '<!-- wp:html --><section class="goetz-intro-section"><div class="goetz-intro">'
        . '<img class="goetz-intro__image" src="' . esc_url($asset_url('welcome_left')) . '" alt="James L. Goetz recognition plaque" loading="lazy">'
        . '<div class="goetz-intro__content"><h2>' . $homepage['welcome']['heading'] . '</h2>'
        . '<img class="goetz-intro__icon" src="' . esc_url($asset_url('scale_icon')) . '" alt="" loading="lazy">'
        . '<p>If you would like to speak with Mr. Goetz, please call <strong>(239) 936-2841</strong> or contact the firm <a href="'
        . esc_url(home_url('/contact/')) . '">online</a>.</p></div>'
        . '<img class="goetz-intro__image" src="' . esc_url($asset_url('welcome_right', '2024/01')) . '" alt="Goetz Legal office library photo" loading="lazy">'
        . '</div></section><!-- /wp:html -->';
    $content .= '<!-- wp:html --><section class="goetz-practice-band"><div class="goetz-practice-band__image">'
        . '<img src="' . esc_url($asset_url('practice_bg')) . '" alt="Law office books and desk" loading="lazy"></div>'
        . '<div class="goetz-practice-band__content"><h2>' . $homepage['practiceAreas']['heading'] . '</h2><ul class="goetz-practice-list">';
    foreach ($homepage['practiceAreas']['items'] as $label) {
        $content .= '<li><span aria-hidden="true"><img src="' . esc_url($asset_url('scale_icon'))
            . '" alt="" loading="lazy"></span><b>' . esc_html((string) $label) . '</b></li>';
    }
    $content .= '</ul></div></section><!-- /wp:html -->';

    $attorneys = $homepage['attorneyGrid']['attorneys'];
    $james_bio = str_replace('father’s', "father's", (string) $attorneys[0]['bio']);
    $content .= '<!-- wp:group {"className":"goetz-section goetz-section--attorneys","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group goetz-section goetz-section--attorneys">'
        . '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Attorneys</h2><!-- /wp:heading -->'
        . '<div class="goetz-card-grid">'
        . $block('goetz/attorney-card', [
            'name'       => $attorneys[0]['name'],
            'bio'        => $james_bio,
            'imageUrl'   => $asset_url('james_card'),
            'imageAlt'   => 'James L. Goetz',
            'profileUrl' => home_url('/james-l-goetz/'),
        ])
        . $block('goetz/attorney-card', [
            'name'       => $attorneys[1]['name'],
            'bio'        => $attorneys[1]['bio'],
            'imageUrl'   => $asset_url('gregory_card', '2025/03'),
            'imageAlt'   => 'Gregory W. Goetz',
            'profileUrl' => home_url('/gregory-w-goetz/'),
        ])
        . '</div></div><!-- /wp:group --><!-- wp:goetz/cta /-->';

    return $content;
}

$homepage_config_path = WP_CONTENT_DIR . '/plugins/goetz-site/config/homepage.php';
if (! is_readable($homepage_config_path)) {
    throw new RuntimeException('The compatibility homepage configuration is missing.');
}
$homepage_config = require $homepage_config_path;
if (! is_array($homepage_config)) {
    throw new RuntimeException('The compatibility homepage configuration must return an array.');
}
$legacy_homepage = goetz_compat_legacy_homepage($homepage_config);

$approved_pages = [
    'home'             => 'Home',
    'james-l-goetz'    => 'James L. Goetz',
    'gregory-w-goetz'  => 'Gregory W. Goetz',
    'staff'            => 'Staff',
    'questions'        => 'Questions',
    'links'            => 'Links',
    'contact'          => 'Contact',
];

$page_ids = [];
foreach ($approved_pages as $slug => $title) {
    $page = get_page_by_path($slug, OBJECT, 'page');
    if ($page instanceof WP_Post) {
        $page_ids[$slug] = (int) $page->ID;
        continue;
    }

    $page_id = wp_insert_post(
        [
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_name'    => $slug,
            'post_title'   => $title,
            'post_content' => $slug === 'home'
                ? $legacy_homepage
                : sprintf('Compatibility fixture for %s.', $title),
        ],
        true
    );

    if (is_wp_error($page_id)) {
        throw new RuntimeException($page_id->get_error_message());
    }

    $page_ids[$slug] = (int) $page_id;
}

update_option('show_on_front', 'page');
update_option('page_on_front', $page_ids['home']);
update_option('page_for_posts', 0);

$published_slugs = get_posts(
    [
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'post_name__in'  => array_keys($approved_pages),
    ]
);

if (count($published_slugs) !== count($approved_pages)) {
    throw new RuntimeException('The compatibility fixture did not create all seven approved pages.');
}

WP_CLI::log(
    wp_json_encode(
        [
            'front_page' => $page_ids['home'],
            'pages'      => $page_ids,
        ],
        JSON_UNESCAPED_SLASHES
    )
);
