<?php

declare(strict_types=1);

namespace Goetz\Site\CLI;

use Goetz\Site\Migrations\Homepage_Migration;

final class Migrate_Command
{
    /**
     * Preview or apply the guarded native homepage migration.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Read-only migration plan.
     *
     * [--apply]
     * : Apply version 1 once. A second apply is a no-op.
     *
     * [--format=<format>]
     * : Must be json.
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assoc_args
     */
    public static function run(array $args, array $assoc_args): void
    {
        if ($args !== []) {
            self::emit(['status' => 'error', 'message' => 'Homepage migration does not accept positional arguments.'], 2);
        }
        $unknown = array_diff(array_keys($assoc_args), ['dry-run', 'apply', 'format', 'force']);
        if ($unknown !== []) {
            self::emit([
                'status'  => 'error',
                'message' => 'Unknown homepage migration option: --' . (string) reset($unknown),
            ], 2);
        }

        $dry_run = \WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);
        $apply = \WP_CLI\Utils\get_flag_value($assoc_args, 'apply', false);
        $format = isset($assoc_args['format']) && is_scalar($assoc_args['format'])
            ? (string) $assoc_args['format']
            : 'json';

        if ($format !== 'json') {
            self::emit(['status' => 'error', 'message' => 'Homepage migration supports only --format=json.'], 2);
        }
        if ($dry_run === $apply) {
            self::emit(['status' => 'error', 'message' => 'Choose exactly one of --dry-run or --apply.'], 2);
        }
        if (array_key_exists('force', $assoc_args)) {
            self::emit(['status' => 'error', 'message' => '--force is not exposed by the deployment command.'], 2);
        }

        try {
            $migration = new Homepage_Migration();
            $result = $dry_run ? $migration->plan() : $migration->apply(false);
        } catch (\Throwable $exception) {
            self::emit(['status' => 'error', 'message' => $exception->getMessage()], 1);
            return;
        }

        $failure = in_array(
            (string) ($result['status'] ?? ''),
            ['invalid_target', 'blocked', 'conflict', 'inconsistent', 'newer_version', 'error'],
            true
        );
        self::emit($result, $failure ? 1 : 0);
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function emit(array $document, int $exit_code): void
    {
        \WP_CLI::line((string) wp_json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($exit_code !== 0) {
            \WP_CLI::halt($exit_code);
        }
    }
}
