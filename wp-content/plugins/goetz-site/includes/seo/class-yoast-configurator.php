<?php

declare(strict_types=1);

namespace Goetz\Site\SEO;

use Goetz\Site\Settings\Site_Settings;
use RuntimeException;

final class Yoast_Configurator
{
    public const SCHEMA_VERSION = '1';
    public const VERSION_OPTION = 'goetz_site_yoast_schema_version';

    private ?bool $available_override;

    /** @var array<string, array{title: string, description: string}> */
    private array $pages;

    /**
     * @param array<string, array{title: string, description: string}>|null $pages
     */
    public function __construct(?bool $available_override = null, ?array $pages = null)
    {
        $this->available_override = $available_override;
        $this->pages = $pages ?? self::load_page_config();
        $this->assert_page_config();
    }

    public function is_available(): bool
    {
        if ($this->available_override !== null) {
            return $this->available_override;
        }

        return class_exists('WPSEO_Options')
            && method_exists('WPSEO_Options', 'get')
            && method_exists('WPSEO_Options', 'set');
    }

    /**
     * @return array<string, mixed>
     */
    public function configure(bool $dry_run = false): array
    {
        if (! $this->is_available()) {
            return self::skipped_result();
        }

        $preflight = $this->preflight();
        if ($preflight['errors'] !== []) {
            return self::blocked_result($preflight['errors']);
        }

        $desired_options = $this->desired_option_values(
            $preflight['logo_id'],
            $preflight['social_image_id']
        );
        $changed_options = $this->configure_options($desired_options, $dry_run);
        $page_result = $this->apply_pages($preflight, $dry_run);
        $version_changed = get_option(self::VERSION_OPTION, null) !== self::SCHEMA_VERSION;

        if (! $dry_run && $version_changed) {
            // The schema version is deliberately the final write. It never bypasses comparisons.
            update_option(self::VERSION_OPTION, self::SCHEMA_VERSION, false);
            if (get_option(self::VERSION_OPTION, null) !== self::SCHEMA_VERSION) {
                throw new RuntimeException('Could not verify the Yoast configuration schema version.');
            }
        }

        $result = [
            'status'          => $dry_run
                ? 'dry-run'
                : (($changed_options + $page_result['changed_meta'] + (int) $version_changed) > 0 ? 'configured' : 'noop'),
            'changed_options' => $changed_options,
            'changed_pages'   => $page_result['changed_pages'],
            'changed_meta'    => $page_result['changed_meta'],
            'changed_version' => (int) $version_changed,
        ];

        if ($dry_run) {
            $result['option_keys'] = $this->changed_option_keys($desired_options);
            $result['page_slugs'] = $page_result['page_slugs'];
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function desired_option_values(int $logo_id, int $social_image_id): array
    {
        $logo_url = wp_get_attachment_url($logo_id);
        $social_image_url = wp_get_attachment_url($social_image_id);
        if (! is_string($logo_url) || $logo_url === '') {
            throw new RuntimeException('Could not resolve the custom logo URL.');
        }
        if (! is_string($social_image_url) || $social_image_url === '') {
            throw new RuntimeException('Could not resolve the social image URL.');
        }
        $settings = Site_Settings::all();

        return [
            'wpseo' => [
                'enable_xml_sitemap'         => true,
                'enable_schema'              => true,
                'tracking'                   => false,
                'semrush_integration_active' => false,
                'wincher_integration_active' => false,
            ],
            'wpseo_titles' => [
                'website_name'                  => (string) $settings['business_name'],
                'alternate_website_name'        => (string) $settings['alternate_name'],
                'company_or_person'             => 'company',
                'company_name'                  => (string) $settings['business_name'],
                'company_alternate_name'        => (string) $settings['alternate_name'],
                'company_logo'                  => $logo_url,
                'company_logo_id'               => $logo_id,
                'org-phone'                     => (string) $settings['phone_e164'],
                'org-email'                     => (string) $settings['email'],
                'disable-author'                => true,
                'disable-date'                  => true,
                'disable-post_format'           => true,
                'disable-attachment'            => true,
                'noindex-page'                  => false,
                'noindex-post'                  => true,
                'noindex-attachment'            => true,
                'noindex-tax-category'          => true,
                'noindex-tax-post_tag'          => true,
                'open_graph_frontpage_image'    => $social_image_url,
                'open_graph_frontpage_image_id' => $social_image_id,
            ],
            'wpseo_social' => [
                'opengraph'           => true,
                'twitter'             => true,
                'twitter_card_type'   => 'summary_large_image',
                'og_default_image'    => $social_image_url,
                'og_default_image_id' => $social_image_id,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function configure_pages(bool $dry_run = false): array
    {
        if (! $this->is_available()) {
            return self::skipped_result();
        }

        $preflight = $this->preflight();
        if ($preflight['errors'] !== []) {
            return self::blocked_result($preflight['errors']);
        }

        $result = $this->apply_pages($preflight, $dry_run);

        return [
            'status'          => $dry_run ? 'dry-run' : ($result['changed_meta'] > 0 ? 'configured' : 'noop'),
            'changed_options' => 0,
            'changed_pages'   => $result['changed_pages'],
            'changed_meta'    => $result['changed_meta'],
            'changed_version' => 0,
            'page_slugs'      => $result['page_slugs'],
        ];
    }

    /**
     * @return array{
     *   errors: array<int, string>,
     *   pages: array<string, object>,
     *   logo_id: int,
     *   social_image_id: int,
     *   social_image_url: string
     * }
     */
    private function preflight(): array
    {
        $errors = [];
        $posts = [];
        foreach ($this->pages as $slug => $metadata) {
            $post = get_page_by_path($slug, 'OBJECT', 'page');
            if (! is_object($post)
                || (int) ($post->ID ?? 0) < 1
                || (string) ($post->post_type ?? '') !== 'page'
                || (string) ($post->post_name ?? '') !== $slug
                || (string) ($post->post_status ?? '') !== 'publish') {
                $errors[] = 'Required published page is missing or invalid: ' . $slug . '.';
                continue;
            }
            $posts[$slug] = $post;
        }

        $logo_id = absint(get_theme_mod('custom_logo', 0));
        if (! $this->is_image_attachment($logo_id)) {
            $errors[] = 'A valid custom logo image attachment is required.';
        } else {
            $logo_url = wp_get_attachment_url($logo_id);
            if (! is_string($logo_url) || $logo_url === '') {
                $errors[] = 'A valid custom logo image attachment is required.';
            }
        }

        $social_image_id = absint(Site_Settings::get('social_image_id', 0));
        $social_image_url = '';
        if (! $this->is_image_attachment($social_image_id)) {
            $errors[] = 'A valid Site Settings social image attachment is required.';
        } else {
            $metadata = wp_get_attachment_metadata($social_image_id);
            if (! is_array($metadata)
                || (int) ($metadata['width'] ?? 0) !== 1200
                || (int) ($metadata['height'] ?? 0) !== 630) {
                $errors[] = 'The Site Settings social image must be exactly 1200x630 pixels.';
            }
            $resolved_url = wp_get_attachment_url($social_image_id);
            if (! is_string($resolved_url) || $resolved_url === '') {
                $errors[] = 'A valid Site Settings social image attachment is required.';
            } else {
                $social_image_url = $resolved_url;
            }
        }

        return [
            'errors'           => array_values(array_unique($errors)),
            'pages'            => $posts,
            'logo_id'          => $logo_id,
            'social_image_id'  => $social_image_id,
            'social_image_url' => $social_image_url,
        ];
    }

    private function is_image_attachment(int $post_id): bool
    {
        return $post_id > 0
            && get_post_type($post_id) === 'attachment'
            && wp_attachment_is_image($post_id);
    }

    /**
     * @param array<string, array<string, mixed>> $desired
     */
    private function configure_options(array $desired, bool $dry_run): int
    {
        $changed = 0;
        foreach ($desired as $group => $values) {
            foreach ($values as $key => $value) {
                $current = \WPSEO_Options::get($key, null, [$group]);
                if ($current === $value) {
                    continue;
                }
                ++$changed;
                if (! $dry_run) {
                    $this->set_yoast_option($group, $key, $value);
                }
            }
        }

        return $changed;
    }

    private function set_yoast_option(string $group, string $key, mixed $desired): void
    {
        $raw_before = get_option($group, []);
        $raw_before = is_array($raw_before) ? $raw_before : [];

        $preserve_unmanaged = static function (mixed $new_value, mixed $old_value, string $option_name = '') use ($raw_before, $key, $desired): array {
            $next = is_array($old_value) ? $old_value : $raw_before;
            $validated = is_array($new_value) && array_key_exists($key, $new_value)
                ? $new_value[$key]
                : $desired;
            $next[$key] = $validated;

            return $next;
        };

        add_filter('pre_update_option_' . $group, $preserve_unmanaged, PHP_INT_MAX, 3);
        try {
            \WPSEO_Options::set($key, $desired, $group);
        } finally {
            remove_filter('pre_update_option_' . $group, $preserve_unmanaged, PHP_INT_MAX);
        }

        if (method_exists('WPSEO_Options', 'clear_cache')) {
            \WPSEO_Options::clear_cache();
        }
        if (\WPSEO_Options::get($key, null, [$group]) !== $desired) {
            throw new RuntimeException('Could not verify approved Yoast option: ' . $group . '.' . $key . '.');
        }

        $raw_after = get_option($group, []);
        $raw_after = is_array($raw_after) ? $raw_after : [];
        foreach ($raw_before as $unmanaged_key => $unmanaged_value) {
            if ((string) $unmanaged_key === $key) {
                continue;
            }
            if (! array_key_exists($unmanaged_key, $raw_after)
                || serialize($raw_after[$unmanaged_key]) !== serialize($unmanaged_value)) {
                throw new RuntimeException('An unmanaged Yoast option changed while updating ' . $group . '.' . $key . '.');
            }
        }
    }

    /**
     * @param array{
     *   pages: array<string, object>,
     *   social_image_id: int,
     *   social_image_url: string
     * } $preflight
     * @return array{changed_pages: int, changed_meta: int, page_slugs: array<int, string>}
     */
    private function apply_pages(array $preflight, bool $dry_run): array
    {
        $changed_pages = 0;
        $changed_meta = 0;
        $page_slugs = [];

        foreach ($this->pages as $slug => $metadata) {
            $post = $preflight['pages'][$slug];
            $post_id = (int) $post->ID;
            $desired_meta = [
                '_yoast_wpseo_title'               => $metadata['title'],
                '_yoast_wpseo_metadesc'            => $metadata['description'],
                '_yoast_wpseo_opengraph-image'     => $preflight['social_image_url'],
                '_yoast_wpseo_opengraph-image-id'  => (string) $preflight['social_image_id'],
                '_yoast_wpseo_twitter-image'       => $preflight['social_image_url'],
                '_yoast_wpseo_twitter-image-id'    => (string) $preflight['social_image_id'],
            ];
            $page_changed = false;

            foreach ($desired_meta as $key => $value) {
                $exists = metadata_exists('post', $post_id, $key);
                $current = $exists ? get_post_meta($post_id, $key, true) : null;
                if ($exists && $current === $value) {
                    continue;
                }
                $page_changed = true;
                ++$changed_meta;
                if (! $dry_run) {
                    update_post_meta($post_id, $key, $value);
                    if (! metadata_exists('post', $post_id, $key)
                        || get_post_meta($post_id, $key, true) !== $value) {
                        throw new RuntimeException('Could not verify approved Yoast page metadata for ' . $slug . '.');
                    }
                }
            }

            if (metadata_exists('post', $post_id, '_yoast_wpseo_canonical')) {
                $page_changed = true;
                ++$changed_meta;
                if (! $dry_run) {
                    delete_post_meta($post_id, '_yoast_wpseo_canonical');
                    if (metadata_exists('post', $post_id, '_yoast_wpseo_canonical')) {
                        throw new RuntimeException('Could not remove the environment-specific canonical for ' . $slug . '.');
                    }
                }
            }

            if ($page_changed) {
                ++$changed_pages;
                $page_slugs[] = $slug;
            }
        }

        return [
            'changed_pages' => $changed_pages,
            'changed_meta'  => $changed_meta,
            'page_slugs'    => $page_slugs,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $desired
     * @return array<int, string>
     */
    private function changed_option_keys(array $desired): array
    {
        $keys = [];
        foreach ($desired as $group => $values) {
            foreach ($values as $key => $value) {
                if (\WPSEO_Options::get($key, null, [$group]) !== $value) {
                    $keys[] = $group . '.' . $key;
                }
            }
        }

        return $keys;
    }

    /**
     * @return array<string, array{title: string, description: string}>
     */
    private static function load_page_config(): array
    {
        $file = dirname(__DIR__, 2) . '/config/seo-pages.php';
        $config = is_readable($file) ? require $file : null;
        if (! is_array($config)) {
            throw new RuntimeException('Portable SEO page configuration is missing.');
        }

        return $config;
    }

    private function assert_page_config(): void
    {
        $expected_slugs = [
            'home',
            'james-l-goetz',
            'gregory-w-goetz',
            'staff',
            'questions',
            'links',
            'contact',
        ];
        if (array_keys($this->pages) !== $expected_slugs) {
            throw new RuntimeException('Portable SEO page configuration must contain the seven approved slugs in order.');
        }
        foreach ($this->pages as $slug => $metadata) {
            if (! is_array($metadata)
                || array_keys($metadata) !== ['title', 'description']
                || ! is_string($metadata['title'])
                || trim($metadata['title']) === ''
                || ! is_string($metadata['description'])
                || trim($metadata['description']) === '') {
                throw new RuntimeException('Portable SEO metadata is invalid for ' . $slug . '.');
            }
        }
    }

    /** @return array<string, int|string> */
    private static function skipped_result(): array
    {
        return [
            'status'          => 'skipped',
            'changed_options' => 0,
            'changed_pages'   => 0,
            'changed_meta'    => 0,
            'changed_version' => 0,
            'message'         => 'Yoast SEO is unavailable.',
        ];
    }

    /**
     * @param array<int, string> $errors
     * @return array<string, mixed>
     */
    private static function blocked_result(array $errors): array
    {
        return [
            'status'          => 'blocked',
            'changed_options' => 0,
            'changed_pages'   => 0,
            'changed_meta'    => 0,
            'changed_version' => 0,
            'errors'          => array_values($errors),
        ];
    }
}
