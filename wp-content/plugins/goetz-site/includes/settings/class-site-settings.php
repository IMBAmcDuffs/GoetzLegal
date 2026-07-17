<?php

declare(strict_types=1);

namespace Goetz\Site\Settings;

final class Site_Settings
{
    public const OPTION_NAME = 'goetz_site_settings';
    public const OPTION_GROUP = 'goetz_site';

    /** @var array<int, string> */
    private const TEXT_FIELDS = [
        'business_name',
        'alternate_name',
        'phone_display',
        'street_address',
        'locality',
        'region',
        'postal_code',
        'country_code',
        'location_label',
        'cta_label',
        'copyright_text',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'business_name'          => 'Goetz & Goetz',
            'alternate_name'         => 'Goetz and Goetz',
            'phone_display'          => '(239) 936-2841',
            'phone_e164'             => '+12399362841',
            'email'                  => 'info@goetzlegal.com',
            'street_address'         => '33 Barkley Cir Ste 100',
            'locality'               => 'Fort Myers',
            'region'                 => 'FL',
            'postal_code'            => '33907',
            'country_code'           => 'US',
            'location_label'         => 'Fort Myers, Florida',
            'cta_label'              => 'Get Consultation',
            'cta_url'                => '/contact/',
            'footer_disclaimer'      => 'The content of this Website is intended to provide general information about Goetz & Goetz. The information provided is not an offer to represent you or create an attorney-client relationship. The content of any E-mail communication, facsimile or correspondence sent to Goetz & Goetz or to any of its attorneys will not, in and of itself, create an attorney-client relationship.',
            'footer_legal_copy'      => 'The hiring of a lawyer is an important decision that should not be based solely upon advertisements. Before you decide, ask us to send you free written information about our qualifications and experience.',
            'copyright_start_year'   => 2024,
            'copyright_text'         => 'Goetz & Goetz. All Rights Reserved',
            'copyright_dynamic_year' => false,
            'social_image_id'        => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION_NAME, []);
        if (! is_array($stored)) {
            $stored = [];
        }

        return self::sanitize_fields($stored, self::defaults(), false);
    }

    public static function get(string $key, mixed $fallback = null): mixed
    {
        $settings = self::all();

        return array_key_exists($key, $settings) ? $settings[$key] : $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    public static function sanitize(mixed $input): array
    {
        $submitted = is_array($input) ? $input : [];

        return self::sanitize_fields($submitted, self::all(), true);
    }

    public static function sanitize_e164(string $value, string $fallback): string
    {
        $candidate = sanitize_text_field($value);

        return preg_match('/^\+[1-9]\d{7,14}$/', $candidate) === 1 ? $candidate : $fallback;
    }

    public static function sanitize_url_or_path(string $value, string $fallback): string
    {
        $candidate = esc_url_raw(trim($value), ['http', 'https']);
        if ($candidate === '') {
            return $fallback;
        }

        if (str_starts_with($candidate, '/') && ! str_starts_with($candidate, '//')) {
            return $candidate;
        }

        $parts = parse_url($candidate);
        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || (string) $parts['host'] === '') {
            return $fallback;
        }

        return $candidate;
    }

    public static function formatted_address(): string
    {
        $settings = self::all();
        $locality = implode(', ', array_filter([
            $settings['locality'],
            trim($settings['region'] . ' ' . $settings['postal_code']),
        ], static fn(string $part): bool => $part !== ''));

        return implode(', ', array_filter([
            $settings['street_address'],
            $locality,
        ], static fn(string $part): bool => $part !== ''));
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $fallbacks
     * @return array<string, mixed>
     */
    private static function sanitize_fields(array $values, array $fallbacks, bool $preserve_omitted): array
    {
        $defaults = self::defaults();
        $sanitized = [];

        foreach ($defaults as $key => $default) {
            $fallback = array_key_exists($key, $fallbacks) ? $fallbacks[$key] : $default;
            if ($preserve_omitted && ! array_key_exists($key, $values)) {
                $sanitized[$key] = $fallback;
                continue;
            }

            $value = array_key_exists($key, $values) ? $values[$key] : $fallback;
            $sanitized[$key] = self::sanitize_field($key, $value, $fallback);
        }

        return $sanitized;
    }

    private static function sanitize_field(string $key, mixed $value, mixed $fallback): mixed
    {
        if (in_array($key, self::TEXT_FIELDS, true)) {
            return is_scalar($value) ? sanitize_text_field((string) $value) : (string) $fallback;
        }

        if ($key === 'phone_e164') {
            return self::sanitize_e164(is_scalar($value) ? (string) $value : '', (string) $fallback);
        }

        if ($key === 'email') {
            $candidate = is_scalar($value) ? sanitize_email((string) $value) : '';

            return $candidate !== '' && is_email($candidate) ? $candidate : (string) $fallback;
        }

        if ($key === 'cta_url') {
            return self::sanitize_url_or_path(is_scalar($value) ? (string) $value : '', (string) $fallback);
        }

        if ($key === 'footer_disclaimer' || $key === 'footer_legal_copy') {
            return is_scalar($value) ? wp_kses_post((string) $value) : (string) $fallback;
        }

        if ($key === 'copyright_start_year') {
            if ((is_int($value) || is_string($value))
                && preg_match('/^\d{4}$/', (string) $value) === 1) {
                $year = (int) $value;
                if ($year >= 1000 && $year <= 9999) {
                    return $year;
                }
            }

            return (int) $fallback;
        }

        if ($key === 'copyright_dynamic_year') {
            if (in_array($value, [true, 1, '1', 'true'], true)) {
                return true;
            }
            if (in_array($value, [false, 0, '0', 'false'], true)) {
                return false;
            }

            return (bool) $fallback;
        }

        if ($key === 'social_image_id') {
            if (in_array($value, [0, '0'], true)) {
                return 0;
            }

            $attachment_id = absint($value);
            if ($attachment_id > 0
                && get_post_type($attachment_id) === 'attachment'
                && wp_attachment_is_image($attachment_id)) {
                return $attachment_id;
            }

            return (int) $fallback;
        }

        return $fallback;
    }
}
