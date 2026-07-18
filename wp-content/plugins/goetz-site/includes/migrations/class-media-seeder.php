<?php

declare(strict_types=1);

namespace Goetz\Site\Migrations;

use RuntimeException;

final class Media_Seeder
{
    public const META_KEY = '_goetz_site_seed_key';
    public const CHECKSUM_META_KEY = '_goetz_site_seed_sha256';

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed>|null $config
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? self::load_config();
    }

    public function seed(string $key): int
    {
        $asset = $this->asset($key);
        $this->validate_seed_file($key, $asset);
        $plan = $this->plan_seed($key, $asset);

        return $this->execute_plan($key, $asset, $plan);
    }

    /**
     * @param array<string, mixed> $asset
     * @param array{status: string, attachment_id: int} $plan
     */
    private function execute_plan(string $key, array $asset, array $plan): int
    {

        if (($plan['status'] ?? '') === 'reuse') {
            $attachment_id = (int) $plan['attachment_id'];
            $this->validate_attachment($key, $attachment_id, $asset);

            return $attachment_id;
        }

        if (($plan['status'] ?? '') === 'claim') {
            $attachment_id = (int) $plan['attachment_id'];
            $this->validate_attachment($key, $attachment_id, $asset);
            $seed_key = (string) $asset['seed_key'];
            if (! add_post_meta($attachment_id, self::META_KEY, $seed_key, true)) {
                throw new RuntimeException("Could not claim the existing {$key} attachment.");
            }
            if (! add_post_meta(
                $attachment_id,
                self::CHECKSUM_META_KEY,
                (string) $asset['sha256'],
                true
            )) {
                delete_post_meta($attachment_id, self::META_KEY, $seed_key);
                throw new RuntimeException("Could not record the managed checksum for {$key}.");
            }

            return $attachment_id;
        }

        return $this->create_attachment($key, $asset);
    }

    /**
     * A dry run performs filesystem/hash/database reads only. In particular it
     * never calls wp_upload_dir(), wp_tempnam(), or an attachment write API.
     *
     * @return array<string, mixed>
     */
    public function seed_all(bool $dry_run = false): array
    {
        $plans = [];
        foreach (array_keys($this->assets()) as $key) {
            $asset = $this->asset($key);
            $this->validate_seed_file($key, $asset);
            $plans[$key] = $this->plan_seed($key, $asset);
        }
        if ($dry_run) {
            return $plans;
        }

        $results = [];
        try {
            foreach ($plans as $key => $plan) {
                $results[$key] = $this->execute_plan($key, $this->asset($key), $plan);
            }
        } catch (\Throwable $exception) {
            foreach ($results as $completed_key => $attachment_id) {
                $completed_plan = $plans[$completed_key];
                if (($completed_plan['status'] ?? '') === 'create') {
                    wp_delete_attachment((int) $attachment_id, true);
                    continue;
                }
                if (($completed_plan['status'] ?? '') === 'claim') {
                    $completed_asset = $this->asset($completed_key);
                    delete_post_meta(
                        (int) $attachment_id,
                        self::META_KEY,
                        (string) $completed_asset['seed_key']
                    );
                    delete_post_meta(
                        (int) $attachment_id,
                        self::CHECKSUM_META_KEY,
                        (string) $completed_asset['sha256']
                    );
                }
            }
            throw $exception;
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public static function load_config(): array
    {
        $path = GOETZ_SITE_PATH . 'config/homepage.php';
        if (! is_readable($path)) {
            throw new RuntimeException('Homepage migration configuration is missing.');
        }

        $config = require $path;
        if (! is_array($config)) {
            throw new RuntimeException('Homepage migration configuration is invalid.');
        }

        return $config;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function assets(): array
    {
        $assets = $this->config['assets'] ?? null;
        if (! is_array($assets) || $assets === []) {
            throw new RuntimeException('Homepage media seed configuration is empty.');
        }

        return $assets;
    }

    /**
     * @return array<string, mixed>
     */
    private function asset(string $key): array
    {
        $assets = $this->assets();
        if (! isset($assets[$key]) || ! is_array($assets[$key])) {
            throw new RuntimeException("Unknown homepage media seed: {$key}");
        }

        return $assets[$key];
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function validate_seed_file(string $key, array $asset): void
    {
        foreach (['seed_key', 'filename', 'sha256', 'mime', 'title', 'alt'] as $field) {
            if (! array_key_exists($field, $asset) || ! is_string($asset[$field])) {
                throw new RuntimeException("Homepage media seed {$key} is missing {$field}.");
            }
        }
        foreach (['width', 'height'] as $field) {
            if (! isset($asset[$field]) || ! is_int($asset[$field]) || $asset[$field] < 1) {
                throw new RuntimeException("Homepage media seed {$key} has an invalid {$field}.");
            }
        }

        $filename = (string) $asset['filename'];
        if ($filename === '' || basename($filename) !== $filename) {
            throw new RuntimeException("Homepage media seed {$key} has an unsafe filename.");
        }

        $mime = (string) $asset['mime'];
        if (! str_starts_with($mime, 'image/')
            || ! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new RuntimeException("Homepage media seed {$key} must declare an accepted image MIME type.");
        }

        $sha256 = (string) $asset['sha256'];
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new RuntimeException("Homepage media seed {$key} has an invalid SHA-256 checksum.");
        }

        $path = $this->seed_path($filename);
        if (! is_readable($path) || hash_file('sha256', $path) !== $sha256) {
            throw new RuntimeException("Homepage media seed {$key} failed checksum validation.");
        }

        $size = getimagesize($path);
        if (! is_array($size)
            || (int) ($size[0] ?? 0) !== $asset['width']
            || (int) ($size[1] ?? 0) !== $asset['height']
            || (string) ($size['mime'] ?? '') !== $mime) {
            throw new RuntimeException("Homepage media seed {$key} failed image MIME or dimension validation.");
        }
    }

    /**
     * @param array<string, mixed> $asset
     * @return array{status: string, attachment_id: int}
     */
    private function plan_seed(string $key, array $asset): array
    {
        $seed_key = (string) $asset['seed_key'];
        $seed_matches = $this->attachment_ids_by_meta(self::META_KEY, $seed_key);
        if (count($seed_matches) > 1) {
            throw new RuntimeException("Homepage media seed {$key} has duplicate seed-key attachments.");
        }
        if ($seed_matches !== []) {
            $attachment_id = $seed_matches[0];
            $seed_meta = get_post_meta($attachment_id, self::META_KEY, false);
            $checksum_meta = get_post_meta($attachment_id, self::CHECKSUM_META_KEY, false);
            if ($seed_meta !== [$seed_key]
                || $checksum_meta !== [(string) $asset['sha256']]) {
                throw new RuntimeException("Homepage media seed {$key} has conflicting managed metadata.");
            }
            $this->validate_attachment($key, $attachment_id, $asset);

            return ['status' => 'reuse', 'attachment_id' => $attachment_id];
        }

        $checksum_matches = [];
        foreach ($this->all_image_attachment_ids() as $attachment_id) {
            if ($this->attachment_original_hash($attachment_id) === (string) $asset['sha256']) {
                $checksum_matches[] = $attachment_id;
            }
        }
        if (count($checksum_matches) > 1) {
            throw new RuntimeException("Homepage media seed {$key} has duplicate exact-byte attachments.");
        }
        if ($checksum_matches !== []) {
            $attachment_id = $checksum_matches[0];
            $existing_keys = get_post_meta($attachment_id, self::META_KEY, false);
            $existing_checksums = get_post_meta($attachment_id, self::CHECKSUM_META_KEY, false);
            if ($existing_keys !== [] || $existing_checksums !== []) {
                throw new RuntimeException("Homepage media seed {$key} conflicts with another managed seed key.");
            }
            $this->validate_attachment($key, $attachment_id, $asset);

            return ['status' => 'claim', 'attachment_id' => $attachment_id];
        }

        return ['status' => 'create', 'attachment_id' => 0];
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function validate_attachment(string $key, int $attachment_id, array $asset): void
    {
        $attachment = get_post($attachment_id);
        if (! $attachment instanceof \WP_Post
            || $attachment->post_type !== 'attachment'
            || $attachment->post_status !== 'inherit'
            || ! wp_attachment_is_image($attachment_id)
            || get_post_mime_type($attachment_id) !== (string) $asset['mime']) {
            throw new RuntimeException("Homepage media seed {$key} points to a non-image or wrong-MIME attachment.");
        }

        $path = $this->attachment_original_path($attachment_id);
        if ($path === '' || ! is_readable($path)
            || hash_file('sha256', $path) !== (string) $asset['sha256']) {
            throw new RuntimeException("Homepage media seed {$key} attachment checksum does not match.");
        }

        $size = getimagesize($path);
        if (! is_array($size)
            || (int) ($size[0] ?? 0) !== $asset['width']
            || (int) ($size[1] ?? 0) !== $asset['height']
            || (string) ($size['mime'] ?? '') !== (string) $asset['mime']) {
            throw new RuntimeException("Homepage media seed {$key} attachment dimensions do not match.");
        }
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function create_attachment(string $key, array $asset): int
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $source = $this->seed_path((string) $asset['filename']);
        $temporary = wp_tempnam((string) $asset['filename']);
        if (! is_string($temporary) || $temporary === '') {
            throw new RuntimeException("Could not allocate a temporary copy for homepage media seed {$key}.");
        }

        $attachment_id = 0;
        try {
            if (! copy($source, $temporary)) {
                throw new RuntimeException("Could not copy homepage media seed {$key} to a temporary file.");
            }

            $inserted = media_handle_sideload(
                [
                    'name'     => (string) $asset['filename'],
                    'tmp_name' => $temporary,
                ],
                0,
                (string) $asset['title'],
                ['post_content' => '']
            );
            if (is_wp_error($inserted)) {
                throw new RuntimeException(
                    "Could not seed homepage media {$key}: " . $inserted->get_error_message()
                );
            }
            $attachment_id = (int) $inserted;
            $this->validate_attachment($key, $attachment_id, $asset);
            if (! add_post_meta($attachment_id, self::META_KEY, (string) $asset['seed_key'], true)) {
                throw new RuntimeException("Could not record the managed seed key for {$key}.");
            }
            if (! add_post_meta(
                $attachment_id,
                self::CHECKSUM_META_KEY,
                (string) $asset['sha256'],
                true
            )) {
                throw new RuntimeException("Could not record the managed checksum for {$key}.");
            }
            update_post_meta($attachment_id, '_wp_attachment_image_alt', (string) $asset['alt']);

            return $attachment_id;
        } catch (\Throwable $exception) {
            if ($attachment_id > 0) {
                wp_delete_attachment($attachment_id, true);
            }
            throw $exception;
        } finally {
            if (is_file($temporary)) {
                wp_delete_file($temporary);
            }
        }
    }

    private function seed_path(string $filename): string
    {
        $directory = realpath(GOETZ_SITE_PATH . 'assets/seed');
        $candidate = GOETZ_SITE_PATH . 'assets/seed/' . $filename;
        $resolved = realpath($candidate);
        if (! is_string($directory)
            || ! is_string($resolved)
            || dirname($resolved) !== $directory
            || is_link($candidate)) {
            throw new RuntimeException('Homepage media seed path escaped the tracked seed directory.');
        }

        return $resolved;
    }

    /**
     * @return array<int, int>
     */
    private function attachment_ids_by_meta(string $meta_key, string $meta_value): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'attachment' AND pm.meta_key = %s AND pm.meta_value = %s ORDER BY pm.post_id ASC, pm.meta_id ASC",
            $meta_key,
            $meta_value
        )));
    }

    /**
     * @return array<int, int>
     */
    private function all_image_attachment_ids(): array
    {
        return array_map('intval', get_posts([
            'post_type'              => 'attachment',
            'post_status'            => 'inherit',
            'post_mime_type'         => 'image',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'suppress_filters'       => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]));
    }

    private function attachment_original_path(int $attachment_id): string
    {
        $original = wp_get_original_image_path($attachment_id, true);
        if (is_string($original) && $original !== '') {
            return $original;
        }

        $attached = get_attached_file($attachment_id, true);

        return is_string($attached) ? $attached : '';
    }

    private function attachment_original_hash(int $attachment_id): string
    {
        $path = $this->attachment_original_path($attachment_id);
        if ($path === '' || ! is_readable($path)) {
            return '';
        }

        $hash = hash_file('sha256', $path);

        return is_string($hash) ? $hash : '';
    }
}
