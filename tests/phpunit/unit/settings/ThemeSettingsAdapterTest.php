<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ThemeSettingsAdapterTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_plugin_inactive_fallback_is_safe_and_matches_the_legacy_contract(): void
    {
        $adapter = GOETZ_TEST_ROOT . '/wp-content/themes/goetz-legal/inc/site-settings.php';
        self::assertFileIsReadable($adapter, 'The plugin-inactive theme settings adapter is missing.');

        foreach ([
            'GOETZ_LEGAL_PHONE_DISPLAY' => '(239) 936-2841',
            'GOETZ_LEGAL_PHONE_TEL'     => '+12399362841',
            'GOETZ_LEGAL_EMAIL'         => 'info@goetzlegal.com',
            'GOETZ_LEGAL_ADDRESS_LINE_1'=> '33 Barkley Cir Ste 100',
            'GOETZ_LEGAL_ADDRESS_LINE_2'=> 'Fort Myers, FL 33907',
        ] as $constant => $value) {
            if (! defined($constant)) {
                define($constant, $value);
            }
        }
        require_once $adapter;

        self::assertSame('(239) 936-2841', goetz_legal_setting('phone_display'));
        self::assertSame('+12399362841', goetz_legal_setting('phone_e164'));
        self::assertSame('info@goetzlegal.com', goetz_legal_setting('email'));
        self::assertSame('33 Barkley Cir Ste 100, Fort Myers, FL 33907', goetz_legal_formatted_address());
        self::assertTrue(goetz_legal_setting('copyright_dynamic_year'));
        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=33%20Barkley%20Cir%20Ste%20100%2C%20Fort%20Myers%2C%20FL%2033907',
            goetz_legal_map_url()
        );
        self::assertSame('fallback', goetz_legal_setting('not_known', 'fallback'));
    }

    public function test_every_required_theme_and_block_consumer_reads_through_the_adapter(): void
    {
        $files = [
            'header'  => GOETZ_TEST_ROOT . '/wp-content/themes/goetz-legal/header.php',
            'footer'  => GOETZ_TEST_ROOT . '/wp-content/themes/goetz-legal/footer.php',
            'contact' => GOETZ_TEST_ROOT . '/wp-content/themes/goetz-legal/template-parts/content-contact.php',
            'schema'  => GOETZ_TEST_ROOT . '/wp-content/plugins/goetz-site/includes/seo/class-schema.php',
            'theme'   => GOETZ_TEST_ROOT . '/wp-content/themes/goetz-legal/functions.php',
            'cta'     => GOETZ_TEST_ROOT . '/wp-content/plugins/goetz-site/blocks/cta/render.php',
        ];
        $sources = array_map(static fn(string $file): string => (string) file_get_contents($file), $files);

        self::assertStringContainsString(
            "require_once __DIR__ . '/inc/site-settings.php';",
            $sources['header'],
            'The header must bootstrap settings independently when PHP-FPM still has the previous functions.php cached.'
        );
        self::assertStringContainsString(
            "require_once __DIR__ . '/inc/site-settings.php';",
            $sources['footer'],
            'The footer must bootstrap settings independently when it is rendered without the header.'
        );
        self::assertStringContainsString(
            "require_once dirname(__DIR__) . '/inc/site-settings.php';",
            $sources['contact'],
            'The contact template must bootstrap settings independently before calling adapter helpers.'
        );

        foreach (['business_name', 'phone_display', 'phone_e164', 'email'] as $key) {
            self::assertStringContainsString("goetz_legal_setting('{$key}'", $sources['header']);
        }
        foreach (['business_name', 'phone_display', 'phone_e164', 'email', 'location_label', 'footer_disclaimer', 'footer_legal_copy', 'copyright_start_year', 'copyright_text', 'copyright_dynamic_year'] as $key) {
            self::assertStringContainsString("goetz_legal_setting('{$key}'", $sources['footer']);
        }
        self::assertStringContainsString('goetz_legal_formatted_address()', $sources['contact']);
        self::assertStringContainsString('goetz_legal_map_url()', $sources['contact']);
        self::assertStringContainsString("goetz_legal_setting('phone_e164'", $sources['contact']);
        self::assertStringContainsString('Site_Settings::all()', $sources['schema']);
        foreach (['business_name', 'alternate_name', 'phone_e164', 'email', 'street_address', 'locality', 'region', 'postal_code', 'country_code', 'location_label'] as $key) {
            self::assertStringContainsString("['{$key}']", $sources['schema']);
        }
        self::assertStringNotContainsString('goetz_legal_schema_fallback', $sources['theme']);
        self::assertStringNotContainsString('application/ld+json', $sources['theme']);
        self::assertStringContainsString('application/ld+json', $sources['schema']);
        self::assertStringContainsString("goetz_site_get_setting('cta_label'", $sources['cta']);
        self::assertStringContainsString("goetz_site_get_setting('cta_url'", $sources['cta']);
    }
}
