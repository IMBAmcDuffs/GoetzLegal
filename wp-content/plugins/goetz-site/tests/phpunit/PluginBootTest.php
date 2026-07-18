<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Goetz\Site\Plugin;
use PHPUnit\Framework\TestCase;

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
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_boot_registers_each_runtime_hook_and_action_once(): void
    {
        $actionRegistrations = 0;
        $filterRegistrations = 0;
        $loadedActions = 0;
        Functions\when('add_action')->alias(
            static function () use (&$actionRegistrations): void {
                ++$actionRegistrations;
            }
        );
        Functions\when('add_filter')->alias(
            static function () use (&$filterRegistrations): void {
                ++$filterRegistrations;
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

        self::assertSame(5, $actionRegistrations);
        self::assertSame(1, $filterRegistrations);
        self::assertSame(1, $loadedActions);
    }
}
