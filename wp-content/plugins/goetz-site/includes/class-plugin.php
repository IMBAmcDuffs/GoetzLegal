<?php

declare(strict_types=1);

namespace Goetz\Site;

require_once __DIR__ . '/settings/class-site-settings.php';
require_once __DIR__ . '/settings/class-settings-page.php';
require_once __DIR__ . '/class-attorney-profiles.php';
require_once __DIR__ . '/migrations/class-media-seeder.php';
require_once __DIR__ . '/migrations/class-site-bootstrap.php';
require_once __DIR__ . '/migrations/class-homepage-migration.php';
require_once __DIR__ . '/editor/class-homepage-editor.php';
require_once __DIR__ . '/cli/class-migrate-command.php';

final class Plugin
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;
        Settings\Settings_Page::hooks();
        Attorney_Profiles::hooks();
        Editor\Homepage_Editor::hooks();
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command(
                'goetz-site migrate homepage',
                [CLI\Migrate_Command::class, 'run']
            );
        }
        add_action('init', [Blocks::class, 'register']);
        add_filter('site_status_tests', __NAMESPACE__ . '\\register_site_health_tests');
        do_action('goetz_site_loaded');
    }
}
