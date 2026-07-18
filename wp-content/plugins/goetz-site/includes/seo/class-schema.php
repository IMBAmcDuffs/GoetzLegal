<?php

declare(strict_types=1);

namespace Goetz\Site\SEO;

use Goetz\Site\Settings\Site_Settings;

final class Schema
{
    private static bool $fallback_rendered = false;

    public static function hooks(): void
    {
        add_filter('wpseo_schema_organization', [self::class, 'filter_organization'], 10, 2);
        add_filter('wpseo_sitemap_exclude_post_type', [self::class, 'exclude_post_type'], 10, 2);
        add_filter('wpseo_sitemap_exclude_taxonomy', [self::class, 'exclude_taxonomy'], 10, 2);
        add_action('wp_head', [self::class, 'render_fallback'], 20);
    }

    /**
     * @param array<string, mixed> $piece
     * @return array<string, mixed>
     */
    public static function filter_organization(array $piece, mixed $context = null): array
    {
        $settings = Site_Settings::all();
        $piece['@type'] = ['Organization', 'LegalService'];
        $piece['url'] = home_url('/');
        $piece['name'] = (string) $settings['business_name'];
        $piece['alternateName'] = (string) $settings['alternate_name'];
        $piece['telephone'] = (string) $settings['phone_e164'];
        $piece['email'] = (string) $settings['email'];
        $piece['address'] = [
            '@type'           => 'PostalAddress',
            'streetAddress'   => (string) $settings['street_address'],
            'addressLocality' => (string) $settings['locality'],
            'addressRegion'   => (string) $settings['region'],
            'postalCode'      => (string) $settings['postal_code'],
            'addressCountry'  => (string) $settings['country_code'],
        ];
        $piece['areaServed'] = [
            '@type' => 'City',
            'name'  => (string) $settings['location_label'],
        ];

        return $piece;
    }

    public static function exclude_post_type(bool $excluded, string $post_type): bool
    {
        return $post_type !== 'page';
    }

    public static function exclude_taxonomy(bool $excluded, string $taxonomy): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public static function fallback_graph(): array
    {
        $home = home_url('/');
        $piece = [
            '@id' => $home . '#organization',
        ];

        $logo_id = absint(get_theme_mod('custom_logo', 0));
        if ($logo_id > 0
            && get_post_type($logo_id) === 'attachment'
            && wp_attachment_is_image($logo_id)) {
            $logo_url = wp_get_attachment_url($logo_id);
            if (is_string($logo_url) && $logo_url !== '') {
                $logo_id_url = $home . '#organizationLogo';
                $piece['logo'] = [
                    '@type'      => 'ImageObject',
                    '@id'        => $logo_id_url,
                    'url'        => $logo_url,
                    'contentUrl' => $logo_url,
                ];
                $piece['image'] = ['@id' => $logo_id_url];
            }
        }

        return [
            '@context' => 'https://schema.org',
            '@graph'   => [self::filter_organization($piece)],
        ];
    }

    public static function render_fallback(): void
    {
        if (self::$fallback_rendered
            || defined('WPSEO_VERSION')
            || class_exists('WPSEO_Options')) {
            return;
        }

        self::$fallback_rendered = true;
        echo '<script type="application/ld+json">'
            . wp_json_encode(
                self::fallback_graph(),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
            )
            . '</script>' . "\n";
    }
}
