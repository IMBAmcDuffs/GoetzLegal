<?php

// Add theme support for various features
function goetz_legal_theme_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', array(
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
    ));
    add_theme_support('custom-logo', array(
        'height' => 100,
        'width' => 300,
        'flex-width' => true,
        'flex-height' => true,
    ));
    add_theme_support('custom-header', array(
        'width' => 1200,
        'height' => 600,
    ));
}
add_action('after_setup_theme', 'goetz_legal_theme_setup');

// Enqueue styles and scripts
function goetz_legal_enqueue_scripts() {
    // Enqueue main stylesheet
    wp_enqueue_style(
        'goetz-legal-style',
        get_template_directory_uri() . '/dist/style.css',
        array(),
        filemtime(get_template_directory() . '/dist/style.css')
    );
    
    // Enqueue main script
    wp_enqueue_script(
        'goetz-legal-script',
        get_template_directory_uri() . '/dist/app.js',
        array(),
        filemtime(get_template_directory() . '/dist/app.js'),
        true
    );
    
    // Localize script for AJAX
    wp_localize_script('goetz-legal-script', 'goetz_legal_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('goetz_legal_nonce')
    ));
}
add_action('wp_enqueue_scripts', 'goetz_legal_enqueue_scripts');

// Add SEO meta tags
function goetz_legal_add_seo_meta_tags() {
    global $post;
    
    // Default meta tags
    $meta_tags = array(
        'description' => 'Goetz & Goetz Law Firm - Providing exceptional legal services with integrity and excellence.',
        'keywords' => 'law firm, legal services, attorney, goetz legal, goetz & goetz',
        'author' => 'Goetz & Goetz',
        'robots' => 'index, follow'
    );
    
    // Override with post-specific meta tags if available
    if (is_single() && $post) {
        $meta_tags['description'] = get_the_excerpt($post);
        $meta_tags['keywords'] = get_post_meta($post->ID, '_meta_keywords', true);
    }
    
    // Output meta tags
    foreach ($meta_tags as $name => $content) {
        echo '<meta name="' . esc_attr($name) . '" content="' . esc_attr($content) . '">' . "\n";
    }
    
    // Add Open Graph meta tags
    echo '<meta property="og:title" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr($meta_tags['description']) . '">' . "\n";
    echo '<meta property="og:type" content="website">' . "\n";
    echo '<meta property="og:url" content="' . esc_url(home_url()) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
    
    // Add Twitter Card meta tags
    echo '<meta name="twitter:card" content="summary">' . "\n";
    echo '<meta name="twitter:title" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
    echo '<meta name="twitter:description" content="' . esc_attr($meta_tags['description']) . '">' . "\n";
}
add_action('wp_head', 'goetz_legal_add_seo_meta_tags');

// Add structured data for schema.org
function goetz_legal_add_structured_data() {
    global $post;
    
    // Only add structured data on the homepage
    if (is_front_page()) {
        $schema_data = array(
            '@context' => 'https://schema.org',
            '@type' => 'Law Firm',
            'name' => get_bloginfo('name'),
            'url' => home_url(),
            'logo' => get_template_directory_uri() . '/images/logo.png',
            'description' => 'Goetz & Goetz Law Firm - Providing exceptional legal services with integrity and excellence.',
            'address' => array(
                '@type' => 'PostalAddress',
                'streetAddress' => '123 Legal Avenue',
                'addressLocality' => 'Chicago',
                'addressRegion' => 'IL',
                'postalCode' => '60601',
                'addressCountry' => 'US'
            ),
            'telephone' => '+1-555-123-4567',
            'email' => 'info@goetzlegal.com',
            'founder' => array(
                '@type' => 'Person',
                'name' => 'John Goetz'
            )
        );
        
        echo '<script type="application/ld+json">' . json_encode($schema_data, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }
}
add_action('wp_head', 'goetz_legal_add_structured_data');

// Add accessibility features
function goetz_legal_add_accessibility_features() {
    // Add skip link for keyboard navigation
    echo '<a href="#main-content" class="skip-link screen-reader-text">' . esc_html__('Skip to main content', 'goetz-legal') . '</a>' . "\n";
}
add_action('wp_body_open', 'goetz_legal_add_accessibility_features');

// Add responsive meta tag
function goetz_legal_add_responsive_meta() {
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
}
add_action('wp_head', 'goetz_legal_add_responsive_meta');

// Add custom CSS for accessibility
function goetz_legal_add_accessibility_css() {
    echo '<style>' . "\n";
    echo '.screen-reader-text { position: absolute; left: -9999px; width: 1px; height: 1px; }' . "\n";
    echo '.skip-link { position: absolute; top: -40px; left: 6px; background: #000; color: #fff; padding: 8px; text-decoration: none; }' . "\n";
    echo '.skip-link:focus { top: 6px; }' . "\n";
    echo '</style>' . "\n";
}
add_action('wp_head', 'goetz_legal_add_accessibility_css');

// Add performance optimizations
function goetz_legal_add_performance_optimizations() {
    // Add preload for critical resources
    echo '<link rel="preload" href="' . get_template_directory_uri() . '/dist/style.css" as="style">' . "\n";
    echo '<link rel="preload" href="' . get_template_directory_uri() . '/dist/app.js" as="script">' . "\n";
}
add_action('wp_head', 'goetz_legal_add_performance_optimizations');

// Add proper HTML5 semantic elements support
function goetz_legal_html5_semantic_elements() {
    echo '<!--[if lt IE 9]>' . "\n";
    echo '<script src="https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>' . "\n";
    echo '<![endif]-->' . "\n";
}
add_action('wp_head', 'goetz_legal_html5_semantic_elements');
