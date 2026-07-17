<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Goetz\Site\Settings\Settings_Page;
use Goetz\Site\Settings\Site_Settings;
use PHPUnit\Framework\TestCase;

foreach ([
    GOETZ_TEST_ROOT . '/wp-content/plugins/goetz-site/includes/settings/class-site-settings.php',
    GOETZ_TEST_ROOT . '/wp-content/plugins/goetz-site/includes/settings/class-settings-page.php',
] as $settings_file) {
    if (is_readable($settings_file)) {
        require_once $settings_file;
    }
}

final class SettingsPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg(1);
        if (! defined('GOETZ_SITE_URL')) {
            define('GOETZ_SITE_URL', 'https://example.test/wp-content/plugins/goetz-site/');
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_hooks_register_only_the_settings_admin_lifecycle(): void
    {
        self::assertTrue(class_exists(Settings_Page::class), 'The Settings_Page API is missing.');
        $hooks = [];
        Functions\when('add_action')->alias(
            static function (string $hook, $callback) use (&$hooks): void {
                $hooks[$hook] = $callback;
            }
        );

        Settings_Page::hooks();

        $hookNames = array_keys($hooks);
        sort($hookNames);
        self::assertSame(['admin_enqueue_scripts', 'admin_init', 'admin_menu'], $hookNames);
        self::assertSame([Settings_Page::class, 'register'], $hooks['admin_init']);
        self::assertSame([Settings_Page::class, 'enqueue'], $hooks['admin_enqueue_scripts']);
        self::assertIsCallable($hooks['admin_menu']);
    }

    public function test_register_uses_the_strict_settings_api_schema_and_all_nineteen_fields(): void
    {
        self::assertTrue(class_exists(Settings_Page::class), 'The Settings_Page API is missing.');
        self::assertTrue(class_exists(Site_Settings::class), 'The Site_Settings API is missing.');
        $registered = [];
        $fields = [];
        Functions\when('register_setting')->alias(
            static function (string $group, string $name, array $args) use (&$registered): void {
                $registered = compact('group', 'name', 'args');
            }
        );
        Functions\when('add_settings_section')->justReturn(null);
        Functions\when('add_settings_field')->alias(
            static function (string $id) use (&$fields): void {
                $fields[] = $id;
            }
        );

        Settings_Page::register();

        self::assertSame(Site_Settings::OPTION_GROUP, $registered['group']);
        self::assertSame(Site_Settings::OPTION_NAME, $registered['name']);
        self::assertSame('array', $registered['args']['type']);
        self::assertSame([Site_Settings::class, 'sanitize'], $registered['args']['sanitize_callback']);
        self::assertSame(Site_Settings::defaults(), $registered['args']['default']);
        self::assertSame(array_keys(Site_Settings::defaults()), $fields);
    }

    public function test_media_is_enqueued_only_on_the_site_settings_screen(): void
    {
        self::assertTrue(class_exists(Settings_Page::class), 'The Settings_Page API is missing.');
        $mediaCalls = 0;
        $scripts = [];
        Functions\when('wp_enqueue_media')->alias(static function () use (&$mediaCalls): void {
            ++$mediaCalls;
        });
        Functions\when('wp_enqueue_script')->alias(
            static function (...$args) use (&$scripts): void {
                $scripts[] = $args;
            }
        );

        Settings_Page::enqueue('dashboard_page_goetz-site-settings');
        Settings_Page::enqueue('settings_page_goetz-site-settings');

        self::assertSame(1, $mediaCalls);
        self::assertCount(1, $scripts);
        self::assertSame('goetz-site-settings-media', $scripts[0][0]);
        self::assertSame(['media-editor'], $scripts[0][2]);
        self::assertTrue($scripts[0][4]);
    }
}
