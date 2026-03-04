<?php
/**
 * Website scraper class for the Goetz Legal Migration Tool.
 *
 * @package GoetzMigration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Goetz_Migration_Scraper
{
    /**
     * Known pages on the Goetz Legal website.
     *
     * @var array<string, array{title: string, type: string, template: string}>
     */
    private array $known_pages = [
        '/'                    => ['title' => 'Home', 'type' => 'page', 'template' => 'page-templates/template-home.php'],
        '/attorneys/'          => ['title' => 'Attorneys', 'type' => 'page', 'template' => 'page-templates/template-attorneys.php'],
        '/practice-areas/'     => ['title' => 'Practice Areas', 'type' => 'page', 'template' => 'page-templates/template-practice-areas.php'],
        '/contact/'            => ['title' => 'Contact', 'type' => 'page', 'template' => 'page-templates/template-contact.php'],
        '/about/'              => ['title' => 'About Us', 'type' => 'page', 'template' => 'page-templates/template-about.php'],
        '/resources/'          => ['title' => 'Resources', 'type' => 'page', 'template' => 'page-templates/template-resources.php'],
        '/florida-links/'      => ['title' => 'Florida Legal Resources', 'type' => 'page', 'template' => ''],
        '/federal-links/'      => ['title' => 'Federal Legal Resources', 'type' => 'page', 'template' => ''],
        '/services/'           => ['title' => 'Services', 'type' => 'page', 'template' => ''],
    ];

    /**
     * Scrape the source website and store page data.
     *
     * @param string $base_url The base URL to scrape.
     * @return array<int, array{title: string, url: string, type: string, content: string, template: string}> Scraped page data.
     */
    public function scrape_site(string $base_url): array
    {
        $scraped_data = [];

        foreach ($this->known_pages as $path => $meta) {
            $url = rtrim($base_url, '/') . $path;
            $page_data = $this->scrape_page($url);

            if ($page_data !== null) {
                $scraped_data[] = [
                    'title'    => $page_data['title'] ?: $meta['title'],
                    'url'      => $url,
                    'type'     => $meta['type'],
                    'content'  => $page_data['content'],
                    'template' => $meta['template'],
                ];
            } else {
                // Use known page meta as fallback
                $scraped_data[] = [
                    'title'    => $meta['title'],
                    'url'      => $url,
                    'type'     => $meta['type'],
                    'content'  => '',
                    'template' => $meta['template'],
                ];
            }
        }

        update_option('goetz_migration_scraped_data', $scraped_data);

        return $scraped_data;
    }

    /**
     * Scrape a single page and extract title and main content.
     *
     * @param string $url The URL to scrape.
     * @return array{title: string, content: string}|null Page data or null on failure.
     */
    private function scrape_page(string $url): ?array
    {
        $response = wp_remote_get($url, [
            'timeout'    => 30,
            'user-agent' => 'GoetzMigration/1.0',
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return null;
        }

        return $this->parse_html($body);
    }

    /**
     * Parse HTML and extract title and main content.
     *
     * @param string $html Raw HTML content.
     * @return array{title: string, content: string} Parsed data.
     */
    private function parse_html(string $html): array
    {
        $title = '';
        $content = '';

        // Extract title
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $title = trim(wp_strip_all_tags($matches[1]));
            // Remove site name suffix if present
            $title = preg_replace('/\s*[-|–]\s*Goetz\s*&?\s*Goetz.*$/i', '', $title) ?? $title;
        }

        // Extract main content area
        // Try common content selectors
        $content_patterns = [
            '/<main[^>]*>(.*?)<\/main>/is',
            '/<article[^>]*>(.*?)<\/article>/is',
            '/<div[^>]*class="[^"]*entry-content[^"]*"[^>]*>(.*?)<\/div>/is',
            '/<div[^>]*id="content"[^>]*>(.*?)<\/div>/is',
        ];

        foreach ($content_patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $content = $matches[1];
                break;
            }
        }

        // Clean up content
        $content = $this->clean_content($content);

        return [
            'title'   => $title,
            'content' => $content,
        ];
    }

    /**
     * Clean extracted HTML content.
     *
     * @param string $content Raw HTML content.
     * @return string Cleaned content.
     */
    private function clean_content(string $content): string
    {
        // Remove script and style tags
        $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content) ?? $content;
        $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content) ?? $content;

        // Remove excessive whitespace
        $content = preg_replace('/\s+/', ' ', $content) ?? $content;

        // Fix known typos
        $content = str_replace('NEED ALAWYER?', 'NEED A LAWYER?', $content);
        $content = str_replace('Need ALawyer?', 'Need A Lawyer?', $content);

        return trim($content);
    }

    /**
     * Generate WordPress posts/pages from scraped data.
     *
     * @return int Number of posts generated.
     */
    public function generate_posts(): int
    {
        $scraped_data = get_option('goetz_migration_scraped_data', []);
        if (empty($scraped_data)) {
            return 0;
        }

        $generated = 0;

        foreach ($scraped_data as $page) {
            $title = $page['title'] ?? '';
            $content = $page['content'] ?? '';
            $type = $page['type'] ?? 'page';
            $template = $page['template'] ?? '';

            // Skip "Hello world" placeholder pages
            if (stripos($title, 'hello world') !== false) {
                continue;
            }

            // Check if page already exists
            $existing = get_page_by_title($title, OBJECT, $type);
            if ($existing) {
                // Update existing page
                wp_update_post([
                    'ID'           => $existing->ID,
                    'post_content' => $content,
                    'post_status'  => 'publish',
                ]);
            } else {
                // Create new page
                $post_id = wp_insert_post([
                    'post_title'   => $title,
                    'post_content' => $content,
                    'post_status'  => 'publish',
                    'post_type'    => $type,
                ]);

                if (!is_wp_error($post_id) && !empty($template)) {
                    update_post_meta($post_id, '_wp_page_template', $template);
                }
            }

            $generated++;
        }

        return $generated;
    }
}
