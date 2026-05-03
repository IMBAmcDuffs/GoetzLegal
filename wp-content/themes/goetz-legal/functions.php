<?php

declare(strict_types=1);

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Theme setup function
 */
function goetz_legal_setup() {
    // Add theme support for title tag
    add_theme_support('title-tag');
    
    // Add theme support for post thumbnails
    add_theme_support('post-thumbnails');
    
    // Add theme support for HTML5
    add_theme_support('html5', [
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
    ]);
    
    // Add theme support for custom logo
    add_theme_support('custom-logo', [
        'height'      => 250,
        'width'       => 250,
        'flex-width'  => true,
        'flex-height' => true,
    ]);
    
    // Add theme support for custom header
    add_theme_support('custom-header', [
        'width'       => 1200,
        'height'      => 600,
        'flex-width'  => true,
        'flex-height' => true,
    ]);
}

add_action('after_setup_theme', 'goetz_legal_setup');

/**
 * Enqueue theme scripts and styles
 */
function goetz_legal_scripts() {
    // Enqueue main stylesheet
    wp_enqueue_style(
        'goetz-legal-style',
        get_template_directory_uri() . '/dist/style.css',
        [],
        filemtime(get_template_directory() . '/dist/style.css')
    );
    
    // Enqueue main script
    wp_enqueue_script(
        'goetz-legal-script',
        get_template_directory_uri() . '/dist/app.js',
        [],
        filemtime(get_template_directory() . '/dist/app.js'),
        true
    );
}

add_action('wp_enqueue_scripts', 'goetz_legal_scripts');

/**
 * Register navigation menus
 */
function goetz_legal_register_menus() {
    register_nav_menus([
        'primary' => __('Primary Menu', 'goetz-legal'),
        'footer'  => __('Footer Menu', 'goetz-legal'),
    ]);
}

add_action('init', 'goetz_legal_register_menus');

/**
 * Add customizer support
 */
function goetz_legal_customize_register($wp_customize) {
    // Add customizer settings here
}

add_action('customize_register', 'goetz_legal_customize_register');

/**
 * Custom excerpt length
 */
function goetz_legal_excerpt_length($length) {
    return 20;
}

add_filter('excerpt_length', 'goetz_legal_excerpt_length');

/**
 * Custom excerpt more
 */
function goetz_legal_excerpt_more($more) {
    return '...';
}

add_filter('excerpt_more', 'goetz_legal_excerpt_more');