<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Goetz\Site\Settings\Settings_Page;
use Goetz\Site\Settings\Site_Settings;
use PHPUnit\Framework\TestCase;

$settings_class = GOETZ_TEST_ROOT . '/wp-content/plugins/goetz-site/includes/settings/class-site-settings.php';
if (is_readable($settings_class)) {
    require_once $settings_class;
}

$settings_page_class = GOETZ_TEST_ROOT . '/wp-content/plugins/goetz-site/includes/settings/class-settings-page.php';
if (is_readable($settings_page_class)) {
    require_once $settings_page_class;
}

final class SiteSettingsTest extends TestCase
{
    /** @var array<string, mixed>|false */
    private $storedOption = false;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(function (string $name, $default = false) {
            if ($name !== 'goetz_site_settings') {
                return $default;
            }

            return $this->storedOption;
        });
        Functions\when('sanitize_text_field')->alias(
            static fn($value): string => trim(strip_tags((string) $value))
        );
        Functions\when('sanitize_email')->alias(
            static fn($value): string => (string) filter_var(trim((string) $value), FILTER_SANITIZE_EMAIL)
        );
        Functions\when('is_email')->alias(
            static fn(string $value) => filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : false
        );
        Functions\when('wp_kses_post')->alias(
            static fn($value): string => strip_tags((string) $value, '<a><br><em><p><strong>')
        );
        Functions\when('esc_url_raw')->alias(static fn($value): string => trim((string) $value));
        Functions\when('absint')->alias(static fn($value): int => abs((int) $value));
        Functions\when('get_post_type')->alias(
            static fn(int $attachmentId): ?string => $attachmentId === 42 ? 'attachment' : ($attachmentId === 43 ? 'post' : null)
        );
        Functions\when('wp_attachment_is_image')->alias(static fn(int $attachmentId): bool => $attachmentId === 42);
        Functions\when('__')->returnArg(1);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_defaults_are_the_exact_strict_nineteen_key_schema(): void
    {
        $this->assertSettingsClassExists();

        self::assertSame([
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
            'copyright_dynamic_year' => true,
            'social_image_id'        => 0,
        ], Site_Settings::defaults());
        self::assertCount(19, Site_Settings::defaults());
    }

    public function test_all_sanitizes_stored_values_merges_defaults_and_drops_unknown_keys(): void
    {
        $this->assertSettingsClassExists();
        $this->storedOption = [
            'business_name' => ' <b>Stored Firm</b> ',
            'email'         => 'not-an-email',
            'cta_url'       => 'javascript:alert(1)',
            'unknown'       => 'must-not-escape',
        ];

        $settings = Site_Settings::all();

        self::assertSame('Stored Firm', $settings['business_name']);
        self::assertSame('info@goetzlegal.com', $settings['email']);
        self::assertSame('/contact/', $settings['cta_url']);
        self::assertSame('(239) 936-2841', $settings['phone_display']);
        self::assertSame(array_keys(Site_Settings::defaults()), array_keys($settings));
        self::assertArrayNotHasKey('unknown', $settings);
    }

    /**
     * @dataProvider textFieldProvider
     */
    public function test_each_plain_text_field_uses_text_sanitization(string $key): void
    {
        $this->assertSettingsClassExists();
        $settings = Site_Settings::sanitize([$key => "  <b>Clean\nValue</b>  "]);

        self::assertSame("Clean\nValue", $settings[$key]);
        $this->assertStrictSchema($settings);
    }

    /**
     * @return array<string, array{string}>
     */
    public function textFieldProvider(): array
    {
        return [
            'business name'  => ['business_name'],
            'alternate name' => ['alternate_name'],
            'phone display'  => ['phone_display'],
            'street address' => ['street_address'],
            'locality'       => ['locality'],
            'region'         => ['region'],
            'postal code'    => ['postal_code'],
            'country code'   => ['country_code'],
            'location label' => ['location_label'],
            'CTA label'      => ['cta_label'],
            'copyright text' => ['copyright_text'],
        ];
    }

    public function test_partial_submission_preserves_every_omitted_current_value(): void
    {
        $this->assertSettingsClassExists();
        $this->storedOption = array_merge(Site_Settings::defaults(), [
            'business_name' => 'Existing Firm',
            'phone_display' => 'Existing Phone',
            'email'         => 'existing@example.com',
            'cta_url'       => '/existing/',
        ]);

        $settings = Site_Settings::sanitize(['location_label' => 'Updated Location']);

        self::assertSame('Existing Firm', $settings['business_name']);
        self::assertSame('Existing Phone', $settings['phone_display']);
        self::assertSame('existing@example.com', $settings['email']);
        self::assertSame('/existing/', $settings['cta_url']);
        self::assertSame('Updated Location', $settings['location_label']);
        $this->assertStrictSchema($settings);
    }

    public function test_email_is_sanitized_and_invalid_input_preserves_the_previous_valid_value(): void
    {
        $this->assertSettingsClassExists();
        $this->storedOption = array_merge(Site_Settings::defaults(), ['email' => 'previous@example.com']);

        self::assertSame(
            'next@example.com',
            Site_Settings::sanitize(['email' => ' next@example.com '])['email']
        );
        self::assertSame(
            'previous@example.com',
            Site_Settings::sanitize(['email' => 'not-an-email'])['email']
        );
    }

    public function test_e164_accepts_only_plus_and_eight_to_fifteen_digits_without_a_leading_zero(): void
    {
        $this->assertSettingsClassExists();
        $this->storedOption = array_merge(Site_Settings::defaults(), ['phone_e164' => '+441234567890']);

        self::assertSame('+12345678', Site_Settings::sanitize(['phone_e164' => ' +12345678 '])['phone_e164']);
        self::assertSame('+441234567890', Site_Settings::sanitize(['phone_e164' => '+012345678'])['phone_e164']);
        self::assertSame('+441234567890', Site_Settings::sanitize(['phone_e164' => '239-555-0199'])['phone_e164']);
        self::assertSame('+441234567890', Site_Settings::sanitize(['phone_e164' => '+1234567890123456'])['phone_e164']);
    }

    public function test_cta_url_accepts_root_relative_or_http_s_only_and_preserves_previous_on_invalid_input(): void
    {
        $this->assertSettingsClassExists();
        $this->storedOption = array_merge(Site_Settings::defaults(), ['cta_url' => '/previous/']);

        self::assertSame('/contact/?from=cta#form', Site_Settings::sanitize(['cta_url' => '/contact/?from=cta#form'])['cta_url']);
        self::assertSame('https://example.com/contact', Site_Settings::sanitize(['cta_url' => ' https://example.com/contact '])['cta_url']);
        self::assertSame('http://example.com/contact', Site_Settings::sanitize(['cta_url' => 'http://example.com/contact'])['cta_url']);
        self::assertSame('/previous/', Site_Settings::sanitize(['cta_url' => '//evil.example/contact'])['cta_url']);
        self::assertSame('/previous/', Site_Settings::sanitize(['cta_url' => 'mailto:evil@example.com'])['cta_url']);
        self::assertSame('/previous/', Site_Settings::sanitize(['cta_url' => 'contact'])['cta_url']);
    }

    public function test_legal_copy_fields_are_sanitized_with_post_kses(): void
    {
        $this->assertSettingsClassExists();
        $settings = Site_Settings::sanitize([
            'footer_disclaimer' => '<p>Allowed <strong>copy</strong><script>alert(1)</script></p>',
            'footer_legal_copy' => '<em>Legal copy</em><iframe src="https://evil.example"></iframe>',
        ]);

        self::assertStringContainsString('<strong>copy</strong>', $settings['footer_disclaimer']);
        self::assertStringNotContainsString('<script', $settings['footer_disclaimer']);
        self::assertStringContainsString('<em>Legal copy</em>', $settings['footer_legal_copy']);
        self::assertStringNotContainsString('<iframe', $settings['footer_legal_copy']);
    }

    public function test_invalid_year_preserves_previous_valid_year_or_default(): void
    {
        $this->assertSettingsClassExists();
        $this->storedOption = array_merge(Site_Settings::defaults(), ['copyright_start_year' => 1998]);

        self::assertSame(2010, Site_Settings::sanitize(['copyright_start_year' => '2010'])['copyright_start_year']);
        self::assertSame(1998, Site_Settings::sanitize(['copyright_start_year' => '0'])['copyright_start_year']);
        self::assertSame(1998, Site_Settings::sanitize(['copyright_start_year' => 'twenty'])['copyright_start_year']);

        $this->storedOption = false;
        self::assertSame(2024, Site_Settings::sanitize(['copyright_start_year' => '10000'])['copyright_start_year']);
    }

    /**
     * @dataProvider explicitBooleanProvider
     */
    public function test_boolean_uses_explicit_conversion($input, bool $expected): void
    {
        $this->assertSettingsClassExists();

        self::assertSame(
            $expected,
            Site_Settings::sanitize(['copyright_dynamic_year' => $input])['copyright_dynamic_year']
        );
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public function explicitBooleanProvider(): array
    {
        return [
            'boolean true'  => [true, true],
            'integer one'   => [1, true],
            'string one'    => ['1', true],
            'string true'   => ['true', true],
            'boolean false' => [false, false],
            'integer zero'  => [0, false],
            'string zero'   => ['0', false],
            'string false'  => ['false', false],
        ];
    }

    public function test_invalid_boolean_preserves_previous_value(): void
    {
        $this->assertSettingsClassExists();
        $this->storedOption = array_merge(Site_Settings::defaults(), ['copyright_dynamic_year' => false]);

        self::assertFalse(Site_Settings::sanitize(['copyright_dynamic_year' => 'sometimes'])['copyright_dynamic_year']);
    }

    public function test_social_image_accepts_only_zero_or_an_existing_image_attachment(): void
    {
        $this->assertSettingsClassExists();
        $this->storedOption = array_merge(Site_Settings::defaults(), ['social_image_id' => 42]);

        self::assertSame(0, Site_Settings::sanitize(['social_image_id' => '0'])['social_image_id']);
        self::assertSame(42, Site_Settings::sanitize(['social_image_id' => '42'])['social_image_id']);
        self::assertSame(42, Site_Settings::sanitize(['social_image_id' => '43'])['social_image_id']);
        self::assertSame(42, Site_Settings::sanitize(['social_image_id' => '44'])['social_image_id']);
        self::assertSame(42, Site_Settings::sanitize(['social_image_id' => '999'])['social_image_id']);
    }

    public function test_get_and_formatted_address_use_sanitized_effective_settings(): void
    {
        $this->assertSettingsClassExists();
        $this->storedOption = array_merge(Site_Settings::defaults(), [
            'street_address' => '100 Main St',
            'locality'       => 'Fort Myers',
            'region'         => 'FL',
            'postal_code'    => '33901',
        ]);

        self::assertSame('100 Main St, Fort Myers, FL 33901', Site_Settings::formatted_address());
        self::assertSame('Fort Myers', Site_Settings::get('locality'));
        self::assertSame('fallback', Site_Settings::get('unknown', 'fallback'));
    }

    private function assertSettingsClassExists(): void
    {
        self::assertTrue(class_exists(Site_Settings::class), 'The Site_Settings API is missing.');
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function assertStrictSchema(array $settings): void
    {
        self::assertSame(array_keys(Site_Settings::defaults()), array_keys($settings));
        self::assertCount(19, $settings);
    }
}
