<?php

declare(strict_types=1);

namespace Goetz\Site;

use JsonException;
use Throwable;

final class Blocks
{
    public const EDITOR_HANDLE = 'goetz-site-block-editor';

    public static function register(): void
    {
        $asset = self::editorAssetData(GOETZ_SITE_PATH . 'build');
        if ($asset !== null) {
            wp_register_script(
                self::EDITOR_HANDLE,
                GOETZ_SITE_URL . 'build/index.js',
                $asset['dependencies'],
                $asset['version'],
                true
            );
            wp_localize_script(
                self::EDITOR_HANDLE,
                'goetzSiteEditorSettings',
                [
                    'phoneLabel'       => (string) \goetz_site_get_setting('phone_display', '(239) 936-2841'),
                    'phoneUrl'         => 'tel:' . (string) \goetz_site_get_setting('phone_e164', '+12399362841'),
                    'onlineUrl'        => '/contact/',
                    'ctaLabel'         => (string) \goetz_site_get_setting('cta_label', 'Get Consultation'),
                    'ctaUrl'           => (string) \goetz_site_get_setting('cta_url', '/contact/'),
                    'attorneyMarkUrl'  => GOETZ_SITE_URL . 'assets/seed/law-scale-icon-purple.png',
                    'ctaBackgroundUrl' => function_exists('goetz_legal_asset_url')
                        ? \goetz_legal_asset_url(
                            'law-updates-bg.jpg',
                            'https://goetzlegal.com/wp-content/uploads/2022/08/law-updates-bg.jpg'
                        )
                        : 'https://goetzlegal.com/wp-content/uploads/2022/08/law-updates-bg.jpg',
                ]
            );
            wp_set_script_translations(self::EDITOR_HANDLE, 'goetz-site');
        }

        foreach (self::scan(GOETZ_SITE_PATH . 'blocks') as $directory) {
            register_block_type($directory);
        }
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_keys(self::scan(GOETZ_SITE_PATH . 'blocks'));
    }

    /**
     * @return array<string, string>
     */
    private static function scan(string $blocks_directory): array
    {
        $root = realpath($blocks_directory);
        if ($root === false || ! is_dir($root) || is_link($blocks_directory)) {
            return [];
        }

        $blocks = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $candidate = $root . DIRECTORY_SEPARATOR . $entry;
            if (is_link($candidate) || ! is_dir($candidate)) {
                continue;
            }

            $directory = realpath($candidate);
            if ($directory === false || dirname($directory) !== $root) {
                continue;
            }

            $metadata_file = $directory . DIRECTORY_SEPARATOR . 'block.json';
            if (is_link($metadata_file) || ! is_file($metadata_file) || ! is_readable($metadata_file)) {
                continue;
            }

            try {
                $metadata = json_decode((string) file_get_contents($metadata_file), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                continue;
            }

            $name = is_array($metadata) && isset($metadata['name']) ? $metadata['name'] : null;
            if (! is_string($name) || preg_match('/^goetz\/[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) !== 1) {
                continue;
            }

            if (! isset($blocks[$name])) {
                $blocks[$name] = $directory;
            }
        }

        ksort($blocks, SORT_STRING);

        return $blocks;
    }

    /**
     * @return array{dependencies: array<int, string>, version: string}|null
     */
    private static function editorAssetData(string $build_directory): ?array
    {
        $script = $build_directory . DIRECTORY_SEPARATOR . 'index.js';
        $metadata_file = $build_directory . DIRECTORY_SEPARATOR . 'index.asset.php';
        if (! is_readable($script) || ! is_readable($metadata_file)) {
            return null;
        }

        try {
            $metadata = require $metadata_file;
        } catch (Throwable $exception) {
            return null;
        }

        if (
            ! is_array($metadata)
            || ! isset($metadata['dependencies'], $metadata['version'])
            || ! is_array($metadata['dependencies'])
            || ! is_string($metadata['version'])
        ) {
            return null;
        }

        foreach ($metadata['dependencies'] as $dependency) {
            if (! is_string($dependency)) {
                return null;
            }
        }

        return [
            'dependencies' => array_values($metadata['dependencies']),
            'version'      => $metadata['version'],
        ];
    }
}
