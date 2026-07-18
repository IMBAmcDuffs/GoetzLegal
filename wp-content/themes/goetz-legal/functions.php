<?php
/**
 * Goetz Legal Theme Functions
 *
 * @package GoetzLegal
 */

if (is_file(__DIR__.'/vendor/autoload_packages.php')) {
    require_once __DIR__.'/vendor/autoload_packages.php';
}

require_once __DIR__ . '/inc/site-health.php';
add_filter('site_status_tests', 'goetz_legal_add_site_status_tests');

define('GOETZ_LEGAL_PHONE_DISPLAY', '(239) 936-2841');
define('GOETZ_LEGAL_PHONE_TEL', '+12399362841');
define('GOETZ_LEGAL_EMAIL', 'info@goetzlegal.com');
define('GOETZ_LEGAL_ADDRESS_LINE_1', '33 Barkley Cir Ste 100');
define('GOETZ_LEGAL_ADDRESS_LINE_2', 'Fort Myers, FL 33907');

require_once __DIR__ . '/inc/site-settings.php';

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
 * Preload the hash-named local Roboto asset emitted by Vite.
 */
function goetz_legal_preload_font(): void
{
    $manifest_path = get_theme_file_path('dist/.vite/manifest.json');
    if (! is_readable($manifest_path)) {
        return;
    }

    $manifest = json_decode((string) file_get_contents($manifest_path), true);
    $font_file = is_array($manifest)
        ? ($manifest['resources/fonts/roboto-latin-300-700.woff2']['file'] ?? '')
        : '';
    if (! is_string($font_file)
        || ! preg_match('#\Aassets/[A-Za-z0-9._-]+\.woff2\z#', $font_file)
    ) {
        return;
    }

    printf(
        '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin="anonymous">' . "\n",
        esc_url(get_theme_file_uri('dist/' . $font_file))
    );
}
add_action('wp_head', 'goetz_legal_preload_font', 1);

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
