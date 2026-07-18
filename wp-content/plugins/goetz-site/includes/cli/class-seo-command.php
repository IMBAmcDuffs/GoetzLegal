<?php

declare(strict_types=1);

namespace Goetz\Site\CLI;

use Goetz\Site\SEO\Yoast_Configurator;

final class SEO_Command
{
    /**
     * Configure the approved portable Yoast SEO contract.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report the exact pending allowlisted changes without writing.
     *
     * [--strict]
     * : Treat unavailable Yoast as a command failure.
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
            self::emit(['status' => 'error', 'message' => 'SEO configuration does not accept positional arguments.'], 2);
        }

        $unknown = array_diff(array_keys($assoc_args), ['dry-run', 'strict', 'format']);
        if ($unknown !== []) {
            self::emit([
                'status'  => 'error',
                'message' => 'Unknown SEO configuration option: --' . (string) reset($unknown),
            ], 2);
        }

        $dry_run = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);
        $strict = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'strict', false);
        $format = isset($assoc_args['format']) && is_scalar($assoc_args['format'])
            ? (string) $assoc_args['format']
            : 'json';
        if ($format !== 'json') {
            self::emit(['status' => 'error', 'message' => 'SEO configuration supports only --format=json.'], 2);
        }

        try {
            $result = (new Yoast_Configurator())->configure($dry_run);
        } catch (\Throwable $exception) {
            self::emit(['status' => 'error', 'message' => 'SEO configuration failed.'], 1);
            return;
        }

        $status = (string) ($result['status'] ?? 'error');
        $failure = in_array($status, ['blocked', 'error'], true)
            || ($strict && $status === 'skipped');
        self::emit($result, $failure ? 1 : 0);
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function emit(array $document, int $exit_code): void
    {
        $encoded = wp_json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded)) {
            $encoded = '{"status":"error","message":"Could not encode the SEO command result."}';
            $exit_code = 1;
        }
        \WP_CLI::line($encoded);
        if ($exit_code !== 0) {
            \WP_CLI::halt($exit_code);
        }
    }
}
