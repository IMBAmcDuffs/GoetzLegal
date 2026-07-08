<?php
/**
 * Goetz Legal Theme Functions
 *
 * @package GoetzLegal
 */

if (is_file(__DIR__.'/vendor/autoload_packages.php')) {
    require_once __DIR__.'/vendor/autoload_packages.php';
}

define('GOETZ_LEGAL_PHONE_DISPLAY', '(239) 936-2841');
define('GOETZ_LEGAL_PHONE_TEL', '+12399362841');
define('GOETZ_LEGAL_EMAIL', 'info@goetzlegal.com');
define('GOETZ_LEGAL_ADDRESS_LINE_1', '33 Barkley Cir Ste 100');
define('GOETZ_LEGAL_ADDRESS_LINE_2', 'Fort Myers, FL 33907');

/**
 * Return an imported media URL by basename, falling back to the live asset URL.
 */
function goetz_legal_asset_url(string $basename, string $fallback_url = ''): string
{
    static $cache = [];

    $cache_key = $basename . '|' . $fallback_url;
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    global $wpdb;

    $attachment_id = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s ORDER BY post_id DESC LIMIT 1",
            '%' . $wpdb->esc_like($basename)
        )
    );

    if ($attachment_id) {
        $url = wp_get_attachment_url($attachment_id);
        if ($url) {
            $cache[$cache_key] = $url;
            return $url;
        }
    }

    $cache[$cache_key] = $fallback_url;

    return $fallback_url;
}

/**
 * Initialize the TailPress theme framework.
 */
function goetz_legal(): TailPress\Framework\Theme
{
    return TailPress\Framework\Theme::instance()
        ->assets(fn($manager) => $manager
            ->withCompiler(new TailPress\Framework\Assets\ViteCompiler, fn($compiler) => $compiler
                ->registerAsset('resources/scss/app.scss')
                ->registerAsset('resources/ts/app.ts')
                ->editorStyleFile('resources/scss/editor-style.scss')
            )
            ->enqueueAssets()
        )
        ->features(fn($manager) => $manager->add(TailPress\Framework\Features\MenuOptions::class))
        ->menus(fn($manager) => $manager
            ->add('primary', __('Primary Menu', 'goetz-legal'))
            ->add('footer', __('Footer Menu', 'goetz-legal'))
        )
        ->themeSupport(fn($manager) => $manager->add([
            'title-tag',
            'custom-logo',
            'post-thumbnails',
            'align-wide',
            'wp-block-styles',
            'responsive-embeds',
            'editor-styles',
            'html5' => [
                'search-form',
                'comment-form',
                'comment-list',
                'gallery',
                'caption',
            ]
        ]));
}

if (class_exists(TailPress\Framework\Theme::class)) {
    goetz_legal();
} else {
    add_action('after_setup_theme', static function (): void {
        add_theme_support('title-tag');
        add_theme_support('custom-logo');
        add_theme_support('post-thumbnails');
        add_theme_support('align-wide');
        add_theme_support('wp-block-styles');
        add_theme_support('responsive-embeds');

        register_nav_menus([
            'primary' => __('Primary Menu', 'goetz-legal'),
            'footer'  => __('Footer Menu', 'goetz-legal'),
        ]);
    });
}

/**
 * Return the live-site navigation structure used as a fallback and by imports.
 *
 * @return array<int, array{label: string, url: string}>
 */
function goetz_legal_nav_items(): array
{
    return [
        ['label' => __('Home', 'goetz-legal'), 'url' => home_url('/')],
        ['label' => __('James L. Goetz', 'goetz-legal'), 'url' => home_url('/james-l-goetz/')],
        ['label' => __('Gregory W. Goetz', 'goetz-legal'), 'url' => home_url('/gregory-w-goetz/')],
        ['label' => __('Staff', 'goetz-legal'), 'url' => home_url('/staff/')],
        ['label' => __('Questions', 'goetz-legal'), 'url' => home_url('/questions/')],
        ['label' => __('Links', 'goetz-legal'), 'url' => home_url('/links/')],
        ['label' => __('Contact', 'goetz-legal'), 'url' => home_url('/contact/')],
    ];
}

/**
 * Register custom blocks from block.json metadata.
 */
function goetz_legal_register_blocks(): void
{
    $blocks_dir = __DIR__ . '/blocks';

    if (!is_dir($blocks_dir)) {
        return;
    }

    foreach (glob($blocks_dir . '/*/block.json') ?: [] as $metadata_file) {
        register_block_type(dirname($metadata_file));
    }
}
add_action('init', 'goetz_legal_register_blocks');

/**
 * Enqueue Google Fonts for Playfair Display, Lato, and Roboto.
 */
function goetz_legal_enqueue_fonts(): void
{
    wp_enqueue_style(
        'goetz-legal-google-fonts',
        'https://fonts.googleapis.com/css2?family=Lato:wght@300;400;700;900&family=Playfair+Display:wght@400;500;600;700;800;900&family=Roboto:wght@400;500;700&display=swap',
        [],
        null
    );
}
add_action('wp_enqueue_scripts', 'goetz_legal_enqueue_fonts');

/**
 * Redirect the imported source homepage slug to the canonical front page.
 */
function goetz_legal_redirect_home_slug(): void
{
    global $wp;

    if (isset($wp->request) && untrailingslashit($wp->request) === 'home') {
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
}
add_action('template_redirect', 'goetz_legal_redirect_home_slug');

/**
 * Add route-specific body classes for pages that use slug-based templates.
 *
 * @param array<int, string> $classes Body classes.
 * @return array<int, string>
 */
function goetz_legal_body_classes(array $classes): array
{
    if (is_page('contact')) {
        $classes[] = 'goetz-contact-template';
    }

    return $classes;
}
add_filter('body_class', 'goetz_legal_body_classes');

/**
 * Register widget areas.
 */
function goetz_legal_widgets_init(): void
{
    register_sidebar([
        'name'          => __('Footer Widget Area', 'goetz-legal'),
        'id'            => 'footer-widgets',
        'description'   => __('Add widgets here to appear in the footer.', 'goetz-legal'),
        'before_widget' => '<div id="%1$s" class="widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="widget-title text-lg font-semibold text-white mb-4">',
        'after_title'   => '</h3>',
    ]);
}
add_action('widgets_init', 'goetz_legal_widgets_init');

// =========================================================================
// Performance Optimizations
// =========================================================================

/**
 * Add resource hints for external origins (preconnect / dns-prefetch).
 *
 * @param array<int, array{href: string, crossorigin?: string}> $hints
 * @param string $relation_type
 * @return array<int, array{href: string, crossorigin?: string}>
 */
function goetz_legal_resource_hints(array $hints, string $relation_type): array
{
    if ($relation_type === 'preconnect') {
        $hints[] = ['href' => 'https://fonts.googleapis.com', 'crossorigin' => 'anonymous'];
        $hints[] = ['href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous'];
    }
    return $hints;
}
add_filter('wp_resource_hints', 'goetz_legal_resource_hints', 10, 2);

/**
 * Remove WordPress emoji scripts and styles for faster page loads.
 */
function goetz_legal_disable_emojis(): void
{
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
}
add_action('init', 'goetz_legal_disable_emojis');

/**
 * Remove query strings from static resources for better caching.
 *
 * @param string $src Script or style source URL.
 * @return string Cleaned URL.
 */
function goetz_legal_remove_query_strings(string $src): string
{
    if (strpos($src, '?ver=') !== false) {
        $src = remove_query_arg('ver', $src);
    }
    return $src;
}
add_filter('style_loader_src', 'goetz_legal_remove_query_strings', 999);
add_filter('script_loader_src', 'goetz_legal_remove_query_strings', 999);

/**
 * Add defer attribute to non-critical scripts for faster rendering.
 *
 * @param string $tag    The full script tag.
 * @param string $handle The script handle.
 * @return string Modified script tag.
 */
function goetz_legal_defer_scripts(string $tag, string $handle): string
{
    // Don't defer admin scripts or already-async/deferred scripts.
    if (is_admin()) {
        return $tag;
    }
    if (strpos($tag, 'defer') !== false || strpos($tag, 'async') !== false) {
        return $tag;
    }

    // Defer all front-end scripts except jQuery core.
    $excluded = ['jquery-core', 'jquery'];
    if (in_array($handle, $excluded, true)) {
        return $tag;
    }

    return str_replace(' src', ' defer src', $tag);
}
add_filter('script_loader_tag', 'goetz_legal_defer_scripts', 10, 2);

/**
 * Add LegalService schema only when Yoast is not handling schema output.
 */
function goetz_legal_schema_fallback(): void
{
    if (defined('WPSEO_VERSION')) {
        return;
    }

    $schema = [
        '@context' => 'https://schema.org',
        '@type'    => 'LegalService',
        'name'     => 'Goetz & Goetz',
        'url'      => home_url('/'),
        'telephone'=> GOETZ_LEGAL_PHONE_DISPLAY,
        'email'    => GOETZ_LEGAL_EMAIL,
        'address'  => [
            '@type'           => 'PostalAddress',
            'streetAddress'   => GOETZ_LEGAL_ADDRESS_LINE_1,
            'addressLocality' => 'Fort Myers',
            'addressRegion'   => 'FL',
            'postalCode'      => '33907',
            'addressCountry'  => 'US',
        ],
        'areaServed' => 'Fort Myers, Florida',
    ];

    echo '<script type="application/ld+json">' . wp_json_encode($schema) . '</script>' . "\n";
}
add_action('wp_head', 'goetz_legal_schema_fallback', 20);

/**
 * Remove unnecessary WordPress header meta tags.
 */
function goetz_legal_clean_head(): void
{
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wlwmanifest_link');
    remove_action('wp_head', 'wp_generator');
    remove_action('wp_head', 'wp_shortlink_wp_head');
}
add_action('after_setup_theme', 'goetz_legal_clean_head');

/**
 * Limit the number of post revisions stored to reduce database bloat.
 *
 * @param int $num Current revision limit.
 * @return int New revision limit.
 */
function goetz_legal_limit_revisions(int $num): int
{
    return 5;
}
add_filter('wp_revisions_to_keep', 'goetz_legal_limit_revisions');
