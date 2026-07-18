<?php

declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function goetz_legal_setting_defaults(): array
{
    return [
        'business_name'          => 'Goetz & Goetz',
        'alternate_name'         => 'Goetz and Goetz',
        'phone_display'          => defined('GOETZ_LEGAL_PHONE_DISPLAY') ? GOETZ_LEGAL_PHONE_DISPLAY : '(239) 936-2841',
        'phone_e164'             => defined('GOETZ_LEGAL_PHONE_TEL') ? GOETZ_LEGAL_PHONE_TEL : '+12399362841',
        'email'                  => defined('GOETZ_LEGAL_EMAIL') ? GOETZ_LEGAL_EMAIL : 'info@goetzlegal.com',
        'street_address'         => defined('GOETZ_LEGAL_ADDRESS_LINE_1') ? GOETZ_LEGAL_ADDRESS_LINE_1 : '33 Barkley Cir Ste 100',
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
        'copyright_dynamic_year' => true,
        'social_image_id'        => 0,
    ];
}

function goetz_legal_setting(string $key, mixed $fallback = null): mixed
{
    $defaults = goetz_legal_setting_defaults();
    $known_fallback = array_key_exists($key, $defaults) ? $defaults[$key] : $fallback;

    if (function_exists('goetz_site_get_setting')
        && class_exists('Goetz\\Site\\Settings\\Site_Settings')
        && function_exists('get_option')) {
        return goetz_site_get_setting($key, $known_fallback);
    }

    return $known_fallback;
}

function goetz_legal_formatted_address(): string
{
    $locality = implode(', ', array_filter([
        (string) goetz_legal_setting('locality', 'Fort Myers'),
        trim(
            (string) goetz_legal_setting('region', 'FL')
            . ' '
            . (string) goetz_legal_setting('postal_code', '33907')
        ),
    ], static fn(string $part): bool => $part !== ''));

    return implode(', ', array_filter([
        (string) goetz_legal_setting('street_address', '33 Barkley Cir Ste 100'),
        $locality,
    ], static fn(string $part): bool => $part !== ''));
}

function goetz_legal_map_url(): string
{
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(goetz_legal_formatted_address());
}
