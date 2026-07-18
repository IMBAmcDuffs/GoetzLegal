<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Goetz\Site\Plugin;
use PHPUnit\Framework\TestCase;

if (! class_exists('WP_CLI')) {
    final class WP_CLI
    {
        /** @var array<int, array{string, callable}> */
        public static array $commands = [];

        public static function add_command(string $name, callable $callback): void
        {
            self::$commands[] = [$name, $callback];
        }
    }
}
if (! defined('WP_CLI')) {
    define('WP_CLI', true);
}

$plugin_class = dirname(__DIR__, 2) . '/includes/class-plugin.php';
if (is_readable($plugin_class)) {
    require_once $plugin_class;
}

final class PluginBootTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        WP_CLI::$commands = [];
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_boot_registers_each_runtime_hook_and_action_once(): void
    {
        $actionRegistrations = [];
        $filterRegistrations = [];
        $loadedActions = 0;
        Functions\when('add_action')->alias(
            static function (string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1) use (&$actionRegistrations): void {
                $actionRegistrations[] = [$hook, self::callbackId($callback), $priority, $acceptedArgs];
            }
        );
        Functions\when('add_filter')->alias(
            static function (string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1) use (&$filterRegistrations): void {
                $filterRegistrations[] = [$hook, self::callbackId($callback), $priority, $acceptedArgs];
            }
        );
        Functions\when('do_action')->alias(
            static function () use (&$loadedActions): void {
                ++$loadedActions;
            }
        );

        Plugin::boot();
        Plugin::boot();
        Plugin::boot();

        foreach ([
            ['admin_menu', 'closure', 10, 1],
            ['admin_init', 'Goetz\\Site\\Settings\\Settings_Page::register', 10, 1],
            ['admin_enqueue_scripts', 'Goetz\\Site\\Settings\\Settings_Page::enqueue', 10, 1],
            ['init', 'Goetz\\Site\\Attorney_Profiles::register_patterns', 20, 1],
            ['wp_head', 'Goetz\\Site\\SEO\\Schema::render_fallback', 20, 1],
            ['init', 'Goetz\\Site\\Blocks::register', 10, 1],
        ] as $expected) {
            self::assertSame(1, count(array_filter(
                $actionRegistrations,
                static fn(array $registration): bool => $registration === $expected
            )), 'Runtime action was not registered exactly once: ' . $expected[0] . ' ' . $expected[1]);
        }
        foreach ([
            ['block_editor_settings_all', 'Goetz\\Site\\Editor\\Homepage_Editor::filter_settings', 10, 2],
            ['wpseo_schema_organization', 'Goetz\\Site\\SEO\\Schema::filter_organization', 10, 2],
            ['wpseo_sitemap_exclude_post_type', 'Goetz\\Site\\SEO\\Schema::exclude_post_type', 10, 2],
            ['wpseo_sitemap_exclude_taxonomy', 'Goetz\\Site\\SEO\\Schema::exclude_taxonomy', 10, 2],
            ['site_status_tests', 'Goetz\\Site\\register_site_health_tests', 10, 1],
        ] as $expected) {
            self::assertSame(1, count(array_filter(
                $filterRegistrations,
                static fn(array $registration): bool => $registration === $expected
            )), 'Runtime filter was not registered exactly once: ' . $expected[0] . ' ' . $expected[1]);
        }
        self::assertSame(1, $loadedActions);
        self::assertSame([
            ['goetz-site attorney-profile', [Goetz\Site\Attorney_Profiles::class, 'cli']],
            ['goetz-site migrate homepage', [Goetz\Site\CLI\Migrate_Command::class, 'run']],
            ['goetz-site seo configure', [Goetz\Site\CLI\SEO_Command::class, 'run']],
        ], WP_CLI::$commands);
    }

    private static function callbackId(mixed $callback): string
    {
        if ($callback instanceof Closure) {
            return 'closure';
        }
        if (is_array($callback) && count($callback) === 2) {
            $owner = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];

            return $owner . '::' . (string) $callback[1];
        }

        return is_string($callback) ? $callback : get_debug_type($callback);
    }
}
