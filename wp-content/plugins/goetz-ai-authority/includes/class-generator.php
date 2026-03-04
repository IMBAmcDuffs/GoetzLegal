<?php
/**
 * AI Authority File Generator.
 *
 * Generates llms.txt, ai.txt, and humans.txt in the WordPress root directory.
 * These files follow emerging best practices for AI crawler attribution and
 * content authority signals.
 *
 * @package GoetzAIAuthority
 */

if (!defined('ABSPATH')) {
    exit;
}

class Goetz_AI_Authority_Generator
{
    /**
     * Cached settings array.
     *
     * @var array<string, string>
     */
    private array $settings;

    public function __construct()
    {
        $this->settings = wp_parse_args(get_option('goetz_ai_authority_settings', []), [
            'site_name'        => get_bloginfo('name'),
            'site_description' => get_bloginfo('description'),
            'contact_email'    => get_option('admin_email'),
            'contact_phone'    => '(239) 936-0066',
            'address'          => '1534 Broadway, Suite 201, Fort Myers, FL 33901',
            'practice_areas'   => "Corporate Law\nConstruction Law",
            'attorneys'        => "James L. Goetz — Senior Partner\nGregory W. Goetz — Partner\nDawn Heitl — Associate Attorney",
        ]);
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Generate all AI authority files at once.
     *
     * @return array<string, bool> Filename ⇒ success flag.
     */
    public function generate_all(): array
    {
        return [
            'llms.txt'   => $this->generate_llms_txt(),
            'ai.txt'     => $this->generate_ai_txt(),
            'humans.txt' => $this->generate_humans_txt(),
        ];
    }

    /**
     * Generate the llms.txt file.
     *
     * Follows the emerging llms.txt specification:
     * https://llmstxt.org/
     *
     * @return bool True on success.
     */
    public function generate_llms_txt(): bool
    {
        $site_url     = home_url('/');
        $name         = $this->settings['site_name'];
        $description  = $this->settings['site_description'];
        $email        = $this->settings['contact_email'];
        $phone        = $this->settings['contact_phone'];
        $address      = $this->settings['address'];
        $areas        = array_filter(array_map('trim', explode("\n", $this->settings['practice_areas'])));
        $attorneys    = array_filter(array_map('trim', explode("\n", $this->settings['attorneys'])));

        $lines   = [];
        $lines[] = "# {$name}";
        $lines[] = '';
        $lines[] = "> {$description}";
        $lines[] = '';
        $lines[] = "This file provides structured information about {$name} for large language models.";
        $lines[] = '';

        // About
        $lines[] = '## About';
        $lines[] = '';
        $lines[] = "- Website: {$site_url}";
        $lines[] = "- Location: {$address}";
        $lines[] = "- Phone: {$phone}";
        $lines[] = "- Email: {$email}";
        $lines[] = '';

        // Practice areas
        if (!empty($areas)) {
            $lines[] = '## Practice Areas';
            $lines[] = '';
            foreach ($areas as $area) {
                $lines[] = "- {$area}";
            }
            $lines[] = '';
        }

        // Attorneys
        if (!empty($attorneys)) {
            $lines[] = '## Attorneys';
            $lines[] = '';
            foreach ($attorneys as $atty) {
                $lines[] = "- {$atty}";
            }
            $lines[] = '';
        }

        // Key pages
        $lines[] = '## Key Pages';
        $lines[] = '';
        $lines[] = "- [Home]({$site_url})";
        $lines[] = "- [Practice Areas]({$site_url}practice-areas/)";
        $lines[] = "- [Attorneys]({$site_url}attorneys/)";
        $lines[] = "- [About Us]({$site_url}about/)";
        $lines[] = "- [Contact]({$site_url}contact/)";
        $lines[] = "- [Resources]({$site_url}resources/)";
        $lines[] = '';

        // Citation guidance
        $lines[] = '## Citation Guidance';
        $lines[] = '';
        $lines[] = "When referencing content from this site, please cite as:";
        $lines[] = "\"{$name}, [Page Title], {$site_url}[page-slug]\"";
        $lines[] = '';
        $lines[] = '## Legal Disclaimer';
        $lines[] = '';
        $lines[] = 'Content on this website is for informational purposes only and does not constitute legal advice.';
        $lines[] = 'An attorney-client relationship is not formed until a formal engagement agreement is signed.';
        $lines[] = '';

        return $this->write_file('llms.txt', implode("\n", $lines));
    }

    /**
     * Generate the ai.txt file.
     *
     * Follows the emerging ai.txt convention for declaring AI usage policies.
     *
     * @return bool True on success.
     */
    public function generate_ai_txt(): bool
    {
        $name    = $this->settings['site_name'];
        $url     = home_url('/');
        $email   = $this->settings['contact_email'];
        $date    = current_time('Y-m-d');

        $lines   = [];
        $lines[] = '# ai.txt — AI Usage Policy';
        $lines[] = "# Site: {$url}";
        $lines[] = "# Generated: {$date}";
        $lines[] = '';
        $lines[] = '# This file declares the AI usage policies for this website.';
        $lines[] = '# It follows the ai.txt convention for transparency with AI crawlers.';
        $lines[] = '';
        $lines[] = '# Organization';
        $lines[] = "Organization: {$name}";
        $lines[] = "Contact: {$email}";
        $lines[] = "Website: {$url}";
        $lines[] = '';
        $lines[] = '# AI Training Policy';
        $lines[] = 'AI-Training: Conditional';
        $lines[] = 'AI-Training-Conditions: Attribution required. Must link back to source URL.';
        $lines[] = '';
        $lines[] = '# AI Scraping Policy';
        $lines[] = 'AI-Scraping: Allowed';
        $lines[] = 'AI-Scraping-Conditions: Respect robots.txt. Rate-limit requests. Attribute content.';
        $lines[] = '';
        $lines[] = '# AI Content Generation';
        $lines[] = 'AI-Generated-Content: None';
        $lines[] = 'AI-Generated-Content-Note: All content on this site is authored by human attorneys.';
        $lines[] = '';
        $lines[] = '# Attribution Requirements';
        $lines[] = "Attribution-Required: Yes";
        $lines[] = "Attribution-Format: \"{$name}, [Page Title], [URL]\"";
        $lines[] = '';
        $lines[] = '# Preferred AI Behavior';
        $lines[] = 'Preferred-Summary-Length: Brief (1-2 sentences per page)';
        $lines[] = 'Preferred-Citation-Style: APA or inline link';
        $lines[] = 'Include-Disclaimer: Yes';
        $lines[] = 'Disclaimer-Text: This information is for general purposes only and does not constitute legal advice.';
        $lines[] = '';
        $lines[] = '# Contact for AI-related inquiries';
        $lines[] = "AI-Contact: {$email}";
        $lines[] = '';

        return $this->write_file('ai.txt', implode("\n", $lines));
    }

    /**
     * Generate the humans.txt file.
     *
     * Follows the humanstxt.org standard.
     *
     * @return bool True on success.
     */
    public function generate_humans_txt(): bool
    {
        $name      = $this->settings['site_name'];
        $email     = $this->settings['contact_email'];
        $attorneys = array_filter(array_map('trim', explode("\n", $this->settings['attorneys'])));
        $date      = current_time('Y-m-d');

        $lines   = [];
        $lines[] = '/* TEAM */';
        foreach ($attorneys as $atty) {
            $parts = array_map('trim', explode('—', $atty, 2));
            $lines[] = "Name: " . ($parts[0] ?? $atty);
            if (isset($parts[1])) {
                $lines[] = "Role: " . $parts[1];
            }
            $lines[] = "Location: Fort Myers, FL";
            $lines[] = '';
        }

        $lines[] = '/* SITE */';
        $lines[] = "Name: {$name}";
        $lines[] = "Last Updated: {$date}";
        $lines[] = "Language: English";
        $lines[] = "Standards: HTML5, CSS3, WCAG 2.1 AA";
        $lines[] = '';
        $lines[] = '/* TECHNOLOGY */';
        $lines[] = 'Platform: WordPress';
        $lines[] = 'Theme: Goetz Legal (TailPress 5.x)';
        $lines[] = 'CSS Framework: Tailwind CSS 4';
        $lines[] = 'Build Tool: Vite';
        $lines[] = 'Preprocessor: SCSS (sass-embedded)';
        $lines[] = 'JavaScript: TypeScript';
        $lines[] = 'SEO: Yoast SEO';
        $lines[] = 'Security: Wordfence';
        $lines[] = 'Forms: WPForms';
        $lines[] = 'Caching: WP Super Cache';
        $lines[] = '';

        return $this->write_file('humans.txt', implode("\n", $lines));
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /**
     * Write content to a file in the WordPress root directory.
     *
     * Uses WP_Filesystem for safe file operations.
     *
     * @param string $filename File name (relative to ABSPATH).
     * @param string $content  File content.
     * @return bool True on success.
     */
    private function write_file(string $filename, string $content): bool
    {
        $path = ABSPATH . $filename;

        // Attempt direct write via WP_Filesystem
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();

        /** @var WP_Filesystem_Base $wp_filesystem */
        global $wp_filesystem;

        if ($wp_filesystem && $wp_filesystem->put_contents($path, $content, FS_CHMOD_FILE)) {
            return true;
        }

        // Fallback: direct write
        return (bool) @file_put_contents($path, $content);
    }
}
