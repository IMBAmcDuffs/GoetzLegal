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
