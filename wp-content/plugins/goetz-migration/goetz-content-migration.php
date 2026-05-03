<?php

/**
 * Plugin Name: Goetz Content Migration
 * Description: Migrates content from source site to WordPress staging
 * Version: 1.0.0
 * Author: Goetz Legal Team
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class GoetzContentMigration {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_migration_menu'));
        add_action('admin_init', array($this, 'run_migration'));
    }

    public function add_migration_menu() {
        add_management_page(
            'Goetz Content Migration',
            'Goetz Migration',
            'manage_options',
            'goetz-migration',
            array($this, 'migration_page')
        );
    }

    public function migration_page() {
        echo '<div class="wrap">
            <h1>Goetz Content Migration</h1>
            <p>This tool migrates attorney profiles, practice areas, blog posts, and media assets to WordPress.</p>
            <form method="post" action="">
                <input type="hidden" name="goetz_migration_nonce" value="'.wp_create_nonce('goetz_migration').'" />
                <input type="submit" name="run_migration" class="button button-primary" value="Run Migration" />
            </form>
        </div>';
    }

    public function run_migration() {
        if (isset($_POST['run_migration']) && wp_verify_nonce($_POST['goetz_migration_nonce'], 'goetz_migration')) {
            $this->migrate_attorney_profiles();
            $this->migrate_practice_areas();
            $this->migrate_blog_posts();
            $this->migrate_media_assets();
            
            echo '<div class="notice notice-success"><p>Migration completed successfully!</p></div>';
        }
    }

    private function migrate_attorney_profiles() {
        // Sample attorney data - in real implementation, this would come from source site
        $attorneys = array(
            array(
                'name' => 'John Goetz',
                'title' => 'Managing Partner',
                'bio' => 'John has over 20 years of experience in corporate law...',
                'disclaimer' => 'This website is for informational purposes only and does not constitute legal advice.',
                'credentials' => 'J.D. Harvard Law School, B.A. Stanford University',
                'email' => 'jgoetz@goetzlegal.com',
                'phone' => '(555) 123-4567'
            ),
            array(
                'name' => 'Sarah Johnson',
                'title' => 'Senior Partner',
                'bio' => 'Sarah specializes in family law and estate planning...',
                'disclaimer' => 'This website is for informational purposes only and does not constitute legal advice.',
                'credentials' => 'J.D. Yale Law School, B.A. University of California',
                'email' => 'sjohnson@goetzlegal.com',
                'phone' => '(555) 987-6543'
            )
        );

        foreach ($attorneys as $attorney) {
            $post_id = wp_insert_post(array(
                'post_title' => $attorney['name'],
                'post_content' => $this->generate_gutenberg_content_for_attorney($attorney),
                'post_status' => 'publish',
                'post_type' => 'attorney',
                'post_author' => 1
            ));

            if ($post_id) {
                // Add custom fields
                update_post_meta($post_id, 'attorney_title', $attorney['title']);
                update_post_meta($post_id, 'attorney_bio', $attorney['bio']);
                update_post_meta($post_id, 'attorney_disclaimer', $attorney['disclaimer']);
                update_post_meta($post_id, 'attorney_credentials', $attorney['credentials']);
                update_post_meta($post_id, 'attorney_email', $attorney['email']);
                update_post_meta($post_id, 'attorney_phone', $attorney['phone']);
            }
        }
    }

    private function generate_gutenberg_content_for_attorney($attorney) {
        $content = '<!-- wp:group -->
<div class="wp-block-group"><!-- wp:heading -->
<h2 class="wp-block-heading">' . esc_html($attorney['name']) . '</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>' . esc_html($attorney['title']) . '</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>' . esc_html($attorney['bio']) . '</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Credentials</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>' . esc_html($attorney['credentials']) . '</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Contact</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Email: ' . esc_html($attorney['email']) . '</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Phone: ' . esc_html($attorney['phone']) . '</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Disclaimer</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>' . esc_html($attorney['disclaimer']) . '</p>
<!-- /wp:paragraph -->

<!-- /wp:group -->';
        
        return $content;
    }

    private function migrate_practice_areas() {
        // Sample practice area data
        $practice_areas = array(
            array(
                'title' => 'Corporate Law',
                'description' => 'We provide comprehensive corporate legal services...',
                'content' => 'Our corporate law practice covers a wide range of services including mergers and acquisitions, corporate governance, and regulatory compliance.'
            ),
            array(
                'title' => 'Family Law',
                'description' => 'Specialized family law services for all your needs...',
                'content' => 'We handle all aspects of family law including divorce, child custody, adoption, and prenuptial agreements.'
            )
        );

        foreach ($practice_areas as $area) {
            $post_id = wp_insert_post(array(
                'post_title' => $area['title'],
                'post_content' => $this->generate_gutenberg_content_for_practice_area($area),
                'post_status' => 'publish',
                'post_type' => 'practice_area',
                'post_author' => 1
            ));

            if ($post_id) {
                update_post_meta($post_id, 'practice_area_description', $area['description']);
                update_post_meta($post_id, 'practice_area_content', $area['content']);
            }
        }
    }

    private function generate_gutenberg_content_for_practice_area($area) {
        $content = '<!-- wp:group -->
<div class="wp-block-group"><!-- wp:heading -->
<h2 class="wp-block-heading">' . esc_html($area['title']) . '</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>' . esc_html($area['description']) . '</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>' . esc_html($area['content']) . '</p>
<!-- /wp:paragraph -->

<!-- /wp:group -->';
        
        return $content;
    }

    private function migrate_blog_posts() {
        // Sample blog post data
        $posts = array(
            array(
                'title' => 'Understanding Corporate Law',
                'excerpt' => 'A comprehensive guide to corporate legal matters...',
                'content' => 'Corporate law is a complex field that governs the formation, operation, and dissolution of corporations...',
                'tags' => 'corporate law, business, legal',
                'meta_description' => 'Learn about corporate law and how it affects businesses.'
            ),
            array(
                'title' => 'Family Law Basics',
                'excerpt' => 'Essential information about family legal matters...',
                'content' => 'Family law covers a wide range of legal issues that affect families...',
                'tags' => 'family law, divorce, custody',
                'meta_description' => 'Understand the basics of family law and legal matters.'
            )
        );

        foreach ($posts as $post_data) {
            $post_id = wp_insert_post(array(
                'post_title' => $post_data['title'],
                'post_content' => $this->generate_gutenberg_content_for_blog_post($post_data),
                'post_excerpt' => $post_data['excerpt'],
                'post_status' => 'publish',
                'post_type' => 'post',
                'post_author' => 1
            ));

            if ($post_id) {
                // Add meta tags
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $post_data['meta_description']);
                wp_set_post_tags($post_id, $post_data['tags']);
            }
        }
    }

    private function generate_gutenberg_content_for_blog_post($post_data) {
        $content = '<!-- wp:group -->
<div class="wp-block-group"><!-- wp:heading -->
<h2 class="wp-block-heading">' . esc_html($post_data['title']) . '</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>' . esc_html($post_data['content']) . '</p>
<!-- /wp:paragraph -->

<!-- /wp:group -->';
        
        return $content;
    }

    private function migrate_media_assets() {
        // In a real implementation, this would download and optimize media assets
        // For now, we'll just log that media migration would happen
        error_log('Media assets migration would occur here');
        
        // This would typically involve:
        // 1. Downloading images from source site
        // 2. Optimizing them
        // 3. Uploading to WordPress media library
        // 4. Returning attachment IDs for use in content
        
        return true;
    }
}

// Initialize the plugin
new GoetzContentMigration();
