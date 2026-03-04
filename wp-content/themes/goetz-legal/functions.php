<?php
/**
 * Goetz Legal Theme Functions
 *
 * @package GoetzLegal
 */

if (is_file(__DIR__.'/vendor/autoload_packages.php')) {
    require_once __DIR__.'/vendor/autoload_packages.php';
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

goetz_legal();

/**
 * Register custom post types for Attorneys and Practice Areas.
 */
function goetz_legal_register_post_types(): void
{
    register_post_type('attorney', [
        'labels' => [
            'name'               => __('Attorneys', 'goetz-legal'),
            'singular_name'      => __('Attorney', 'goetz-legal'),
            'add_new_item'       => __('Add New Attorney', 'goetz-legal'),
            'edit_item'          => __('Edit Attorney', 'goetz-legal'),
            'view_item'          => __('View Attorney', 'goetz-legal'),
            'all_items'          => __('All Attorneys', 'goetz-legal'),
            'search_items'       => __('Search Attorneys', 'goetz-legal'),
            'not_found'          => __('No attorneys found.', 'goetz-legal'),
        ],
        'public'             => true,
        'has_archive'        => true,
        'rewrite'            => ['slug' => 'attorneys'],
        'supports'           => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
        'menu_icon'          => 'dashicons-businessperson',
        'show_in_rest'       => true,
    ]);

    register_post_type('practice_area', [
        'labels' => [
            'name'               => __('Practice Areas', 'goetz-legal'),
            'singular_name'      => __('Practice Area', 'goetz-legal'),
            'add_new_item'       => __('Add New Practice Area', 'goetz-legal'),
            'edit_item'          => __('Edit Practice Area', 'goetz-legal'),
            'view_item'          => __('View Practice Area', 'goetz-legal'),
            'all_items'          => __('All Practice Areas', 'goetz-legal'),
            'search_items'       => __('Search Practice Areas', 'goetz-legal'),
            'not_found'          => __('No practice areas found.', 'goetz-legal'),
        ],
        'public'             => true,
        'has_archive'        => true,
        'rewrite'            => ['slug' => 'practice-areas'],
        'supports'           => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
        'menu_icon'          => 'dashicons-portfolio',
        'show_in_rest'       => true,
    ]);
}
add_action('init', 'goetz_legal_register_post_types');

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
