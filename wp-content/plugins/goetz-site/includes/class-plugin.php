<?php

declare(strict_types=1);

namespace Goetz\Site;

final class Plugin
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;
        add_action('init', [Blocks::class, 'register']);
        add_filter('site_status_tests', __NAMESPACE__ . '\\register_site_health_tests');
        do_action('goetz_site_loaded');
    }
}
