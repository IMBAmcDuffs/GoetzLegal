<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

$theme_health_file = GOETZ_TEST_ROOT . '/wp-content/themes/goetz-legal/inc/site-health.php';
if (is_readable($theme_health_file)) {
    require_once $theme_health_file;
}

final class ThemeSiteHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg(1);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_missing_plugin_runtime_is_a_non_fatal_critical_site_health_result(): void
    {
        self::assertTrue(
            function_exists('goetz_legal_site_plugin_runtime_test'),
            'The theme fallback Site Health test is missing.'
        );
        Functions\when('did_action')->justReturn(0);

        $result = goetz_legal_site_plugin_runtime_test();

        self::assertSame('critical', $result['status']);
        self::assertSame('goetz_site_plugin_runtime', $result['test']);
    }

    public function test_booted_plugin_runtime_is_a_good_site_health_result(): void
    {
        self::assertTrue(
            function_exists('goetz_legal_site_plugin_runtime_test'),
            'The theme fallback Site Health test is missing.'
        );
        Functions\when('did_action')->justReturn(1);

        self::assertSame('good', goetz_legal_site_plugin_runtime_test()['status']);
    }
}
