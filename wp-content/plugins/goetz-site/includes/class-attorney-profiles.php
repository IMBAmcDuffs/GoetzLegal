<?php

declare(strict_types=1);

namespace Goetz\Site;

final class Attorney_Profiles
{
    public const VERSION = 1;
    public const VERSION_META = '_goetz_attorney_profile_version';
    public const BACKUP_META = '_goetz_attorney_profile_backup_v1';
    private const SEED_META = '_goetz_attorney_profile_seed_key';
    private const JAMES_PORTRAIT_FILENAME = 'JAMES-L-2.jpg';
    private const JAMES_PORTRAIT_SEED_KEY = 'james-l-goetz:portrait:v1';
    private const JAMES_PORTRAIT_SHA256 = '56678363da05812f16be9f34a3ffb13ca450fc33a848022c5ea69f35b7c6fadc';
    private const JAMES_LEGACY_PORTRAIT_URL = 'https://goetzlegal.com/wp-content/uploads/2022/08/JAMES-L.jpg';

    public static function hooks(): void
    {
        add_action('init', [self::class, 'register_patterns'], 20);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('goetz-site attorney-profile', [self::class, 'cli']);
        }
    }

    public static function register_patterns(): void
    {
        if (! function_exists('register_block_pattern')) {
            return;
        }

        if (function_exists('register_block_pattern_category')) {
            register_block_pattern_category(
                'goetz',
                ['label' => __('Goetz & Goetz', 'goetz-site')]
            );
        }

        if (\WP_Block_Patterns_Registry::get_instance()->is_registered('goetz/attorney-profile-page')) {
            return;
        }

        register_block_pattern(
            'goetz/attorney-profile-page',
            [
                'title'      => __('Attorney Profile Page', 'goetz-site'),
                'description'=> __('Editable attorney portrait, biography, email link, and consultation call to action.', 'goetz-site'),
                'categories' => ['goetz'],
                'postTypes'  => ['page'],
                'inserter'   => true,
                'content'    => self::pattern_content(),
            ]
        );
    }

    public static function pattern_content(): string
    {
        return '<!-- wp:group {"className":"goetz-attorney-profile-section","layout":{"type":"default"}} -->'
            . '<div class="wp-block-group goetz-attorney-profile-section">'
            . '<!-- wp:goetz/attorney-card {"name":"Attorney Name","bio":"Add the attorney biography.","email":"info@goetzlegal.com","imageAlt":"Attorney portrait","className":"is-style-profile"} /-->'
            . '</div>'
            . '<!-- /wp:group -->'
            . '<!-- wp:goetz/cta /-->';
    }

    /**
     * Return repository-owned profile content with its local Media Library
     * attachment resolved at runtime.
     *
     * @return array<string, mixed>|null
     */
    public static function profile_for_slug(string $slug): ?array
    {
        if ($slug !== 'james-l-goetz') {
            return null;
        }

        $image_id = self::attachment_id_by_seed_key(self::JAMES_PORTRAIT_SEED_KEY);
        if (! self::attachment_matches_seed($image_id, self::JAMES_PORTRAIT_SHA256)) {
            $candidate_id = self::attachment_id_by_basename(self::JAMES_PORTRAIT_FILENAME);
            $image_id = self::attachment_matches_seed($candidate_id, self::JAMES_PORTRAIT_SHA256)
                ? $candidate_id
                : 0;
        }
        $image_url = $image_id > 0 ? (string) wp_get_attachment_url($image_id) : '';
        $email = function_exists('goetz_site_get_setting')
            ? (string) goetz_site_get_setting('email', 'info@goetzlegal.com')
            : 'info@goetzlegal.com';
        $legacy_image_url = self::localized_source_media_url(self::JAMES_LEGACY_PORTRAIT_URL);
        $bio = 'James L. Goetz was born in Erie, Pennsylvania. He grew up in Oil City and Girard, Pennsylvania working on his father’s farm and coal mines until he went to college. Mr. Goetz’s received his B.A. in political science and a minor in economics from the University of Pittsburgh in 1969. He earned his Juris Doctorate from University of Akron in 1972. From 1972 to 1975, Mr. Goetz served as a Captain in the Judge Advocate General Corps of the United States Army. Mr. Goetz later moved to Fort Myers to begin practicing law at Roberts and Watson, where he later became a partner. Mr. Goetz has been practicing law in Fort Myers for more than 50 years. Mr. Goetz’s Practice Areas include: Estates, Real Estate, Trial, Probate, Construction Law, Bankruptcy, and Commercial Litigation. Mr. Goetz was admitted to the Ohio Bar, Florida Bar, and U.S. Court of Military Appeals in 1972, U.S. Supreme Court in 1976, and also admitted to practice in the United States District Court, Middle District of Florida. Mr. Goetz is a member of the Florida and Ohio State Bar and is also a member of the Lee County Bar association.';
        $legacy_bio = strtr($bio, [
            'father’s'             => "father's",
            'Mr. Goetz’s received' => 'Mr. Goetz received',
            'Mr. Goetz’s Practice' => "Mr. Goetz's Practice",
        ]);

        return [
            'name'             => 'James L. Goetz',
            'bio'              => $bio,
            'legacyBio'        => $legacy_bio,
            'email'            => sanitize_email($email),
            'legacyEmail'      => 'info@goetzlegal.com',
            'imageId'          => $image_id,
            'imageUrl'         => $image_url,
            'imageAlt'         => 'James L. Goetz portrait',
            'legacyImageUrl'   => $legacy_image_url,
            'legacyImageAlt'   => 'James L. Goetz',
            'legacyEmailLabel' => 'Email James L. Goetz',
        ];
    }

    /**
     * Build a read-only migration plan for one configured profile slug.
     *
     * @return array<string, int|string>
     */
    public static function plan_slug(string $slug): array
    {
        $profile = self::profile_for_slug($slug);
        if ($profile === null) {
            return ['slug' => $slug, 'status' => 'unknown_profile', 'post_id' => 0];
        }

        $page = get_page_by_path($slug, OBJECT, 'page');
        if (! $page instanceof \WP_Post) {
            return ['slug' => $slug, 'status' => 'missing_page', 'post_id' => 0];
        }

        if ((int) ($profile['imageId'] ?? 0) < 1 || (string) ($profile['imageUrl'] ?? '') === '') {
            return ['slug' => $slug, 'status' => 'missing_image', 'post_id' => (int) $page->ID];
        }

        $desired_content = self::canonical_content($profile);
        if ($page->post_content === $desired_content) {
            return ['slug' => $slug, 'status' => 'noop', 'post_id' => (int) $page->ID];
        }

        if ((int) get_post_meta($page->ID, self::VERSION_META, true) >= self::VERSION) {
            return ['slug' => $slug, 'status' => 'managed_modified', 'post_id' => (int) $page->ID];
        }

        return [
            'slug'    => $slug,
            'status'  => self::matches_legacy_content($page->post_content, $profile) ? 'ready' : 'conflict',
            'post_id' => (int) $page->ID,
        ];
    }

    /**
     * Preview by default; pass --apply to update only a recognized legacy page.
     *
     * ## OPTIONS
     *
     * [--slug=<slug>]
     * : Configured attorney slug. Defaults to james-l-goetz.
     *
     * [--apply]
     * : Apply the guarded migration. Without this flag the command is read-only.
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assoc_args
     */
    public static function cli(array $args, array $assoc_args): void
    {
        $slug = isset($assoc_args['slug']) && is_scalar($assoc_args['slug'])
            ? sanitize_title((string) $assoc_args['slug'])
            : 'james-l-goetz';
        $apply = \WP_CLI\Utils\get_flag_value($assoc_args, 'apply', false);
        $plan = self::plan_slug($slug);

        if (! $apply) {
            \WP_CLI::line((string) wp_json_encode($plan, JSON_UNESCAPED_SLASHES));
            return;
        }

        if (($plan['status'] ?? '') === 'missing_image') {
            if (self::ensure_media_for_slug($slug) < 1) {
                \WP_CLI::error('Attorney profile migration blocked: portrait seed failed');
            }
            $plan = self::plan_slug($slug);
        }

        if (($plan['status'] ?? '') === 'noop') {
            \WP_CLI::success("Attorney profile {$slug} is already current.");
            return;
        }

        if (($plan['status'] ?? '') !== 'ready') {
            \WP_CLI::error('Attorney profile migration blocked: ' . (string) ($plan['status'] ?? 'unknown'));
        }

        $profile = self::profile_for_slug($slug);
        $result = is_array($profile)
            ? self::apply_to_post((int) $plan['post_id'], $profile)
            : ['status' => 'unknown_profile'];

        if (($result['status'] ?? '') !== 'updated') {
            \WP_CLI::error('Attorney profile migration failed: ' . (string) ($result['status'] ?? 'unknown'));
        }

        \WP_CLI::success("Migrated attorney profile {$slug}.");
    }

    /**
     * Import the exact repository-owned portrait once and reuse it by a stable
     * attachment meta key. This method writes only when explicitly called by
     * the CLI's --apply path.
     */
    public static function ensure_media_for_slug(string $slug): int
    {
        if ($slug !== 'james-l-goetz') {
            return 0;
        }

        $source = GOETZ_SITE_PATH . 'assets/seed/' . self::JAMES_PORTRAIT_FILENAME;
        if (! is_readable($source)
            || hash_file('sha256', $source) !== self::JAMES_PORTRAIT_SHA256) {
            return 0;
        }

        $attachment_id = self::attachment_id_by_seed_key(self::JAMES_PORTRAIT_SEED_KEY);
        if (self::attachment_matches_seed($attachment_id, self::JAMES_PORTRAIT_SHA256)) {
            return $attachment_id;
        }

        $attachment_id = self::attachment_id_by_basename(self::JAMES_PORTRAIT_FILENAME);
        if (self::attachment_matches_seed($attachment_id, self::JAMES_PORTRAIT_SHA256)) {
            update_post_meta($attachment_id, self::SEED_META, self::JAMES_PORTRAIT_SEED_KEY);
            self::ensure_portrait_alt_text($attachment_id);
            return $attachment_id;
        }

        $contents = file_get_contents($source);
        if (! is_string($contents) || $contents === '') {
            return 0;
        }

        $upload = wp_upload_bits(self::JAMES_PORTRAIT_FILENAME, null, $contents);
        if (! empty($upload['error']) || empty($upload['file']) || empty($upload['url'])) {
            return 0;
        }

        $file = (string) $upload['file'];
        $file_type = wp_check_filetype_and_ext($file, basename($file));
        $mime_type = isset($file_type['type']) && is_string($file_type['type'])
            ? $file_type['type']
            : '';
        if ($mime_type !== 'image/jpeg' || ! self::file_matches_hash($file, self::JAMES_PORTRAIT_SHA256)) {
            wp_delete_file($file);
            return 0;
        }

        $inserted = wp_insert_attachment(
            [
                'post_mime_type' => $mime_type,
                'post_title'     => 'James L. Goetz portrait',
                'post_content'   => '',
                'post_status'    => 'inherit',
            ],
            $file,
            0,
            true
        );
        if (is_wp_error($inserted)) {
            wp_delete_file($file);
            return 0;
        }

        $attachment_id = (int) $inserted;
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachment_id, $file);
        if (is_array($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        update_post_meta($attachment_id, self::SEED_META, self::JAMES_PORTRAIT_SEED_KEY);
        self::ensure_portrait_alt_text($attachment_id);

        return valid_image_attachment_id($attachment_id);
    }

    /**
     * Replace only the known legacy profile shape. A migrated page is never
     * overwritten again after an editor changes it.
     *
     * @param array<string, mixed> $profile
     * @return array{status: string, post_id: int, error?: string}
     */
    public static function apply_to_post(int $post_id, array $profile): array
    {
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post || $post->post_type !== 'page') {
            return ['status' => 'missing', 'post_id' => $post_id];
        }

        $desired_content = self::canonical_content($profile);
        if ($post->post_content === $desired_content) {
            return ['status' => 'noop', 'post_id' => $post_id];
        }

        if ((int) get_post_meta($post_id, self::VERSION_META, true) >= self::VERSION) {
            return ['status' => 'managed_modified', 'post_id' => $post_id];
        }

        if (! self::matches_legacy_content($post->post_content, $profile)) {
            return ['status' => 'conflict', 'post_id' => $post_id];
        }

        if (! metadata_exists('post', $post_id, self::BACKUP_META)) {
            add_post_meta($post_id, self::BACKUP_META, $post->post_content, true);
        }

        wp_save_post_revision($post_id);
        $updated = wp_update_post(
            [
                'ID'           => $post_id,
                'post_content' => $desired_content,
            ],
            true
        );

        if (is_wp_error($updated)) {
            return [
                'status'  => 'error',
                'post_id' => $post_id,
                'error'   => $updated->get_error_message(),
            ];
        }

        update_post_meta($post_id, self::VERSION_META, self::VERSION);

        return ['status' => 'updated', 'post_id' => $post_id];
    }

    /**
     * @param array<string, mixed> $profile
     */
    public static function canonical_content(array $profile): string
    {
        $scalar = static fn(string $key): string => isset($profile[$key]) && is_scalar($profile[$key])
            ? (string) $profile[$key]
            : '';

        $card_attributes = [
            'name'      => $scalar('name'),
            'bio'       => $scalar('bio'),
            'email'     => sanitize_email($scalar('email')),
            'imageId'   => absint($profile['imageId'] ?? 0),
            'imageUrl'  => esc_url_raw($scalar('imageUrl')),
            'imageAlt'  => $scalar('imageAlt'),
            'className' => 'is-style-profile',
        ];

        $group_attributes = [
            'className' => 'goetz-attorney-profile-section',
            'layout'    => ['type' => 'default'],
        ];

        return '<!-- wp:group ' . wp_json_encode($group_attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' -->'
            . '<div class="wp-block-group goetz-attorney-profile-section">'
            . '<!-- wp:goetz/attorney-card ' . wp_json_encode($card_attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' /-->'
            . '</div>'
            . '<!-- /wp:group -->'
            . '<!-- wp:goetz/cta /-->';
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function matches_legacy_content(string $content, array $profile): bool
    {
        $blocks = array_values(array_filter(
            parse_blocks($content),
            static fn(array $block): bool => $block['blockName'] !== null
        ));

        if (count($blocks) !== 2
            || $blocks[0]['blockName'] !== 'core/group'
            || $blocks[1]['blockName'] !== 'goetz/cta'
            || ! self::attributes_match_exactly(
                $blocks[0]['attrs'] ?? null,
                ['className' => 'goetz-section', 'layout' => ['type' => 'constrained']]
            )
            || ! self::attributes_match_exactly($blocks[1]['attrs'] ?? null, [])) {
            return false;
        }

        $inner = $blocks[0]['innerBlocks'] ?? [];
        if (count($inner) !== 3
            || $inner[0]['blockName'] !== 'goetz/attorney-card'
            || $inner[1]['blockName'] !== 'core/paragraph'
            || $inner[2]['blockName'] !== 'core/paragraph'
            || ! self::attributes_match_exactly($inner[1]['attrs'] ?? null, [])
            || ! self::attributes_match_exactly($inner[2]['attrs'] ?? null, [])) {
            return false;
        }

        $name = isset($profile['name']) && is_scalar($profile['name']) ? (string) $profile['name'] : '';
        $legacy_email = isset($profile['legacyEmail']) && is_scalar($profile['legacyEmail'])
            ? (string) $profile['legacyEmail']
            : (isset($profile['email']) && is_scalar($profile['email']) ? (string) $profile['email'] : '');
        $legacy_image_url = isset($profile['legacyImageUrl']) && is_scalar($profile['legacyImageUrl'])
            ? (string) $profile['legacyImageUrl']
            : '';
        $legacy_image_alt = isset($profile['legacyImageAlt']) && is_scalar($profile['legacyImageAlt'])
            ? (string) $profile['legacyImageAlt']
            : '';
        $bio = isset($profile['legacyBio']) && is_scalar($profile['legacyBio'])
            ? (string) $profile['legacyBio']
            : (isset($profile['bio']) && is_scalar($profile['bio']) ? (string) $profile['bio'] : '');
        $legacy_email_label = isset($profile['legacyEmailLabel']) && is_scalar($profile['legacyEmailLabel'])
            ? (string) $profile['legacyEmailLabel']
            : 'Email ' . $name;

        return self::attributes_match_exactly(
            $inner[0]['attrs'] ?? null,
            [
                'name'     => $name,
                'email'    => $legacy_email,
                'imageUrl' => $legacy_image_url,
                'imageAlt' => $legacy_image_alt,
            ]
        )
            && self::legacy_paragraph_matches($inner[1], $bio)
            && self::legacy_paragraph_matches($inner[2], $legacy_email_label);
    }

    /**
     * A legacy page is an overwrite candidate only when every serialized
     * attribute still matches the repository-generated shape. Extra/defaulted
     * attributes count as editor changes and fail closed.
     *
     * @param mixed $actual
     * @param array<string, mixed> $expected
     */
    private static function attributes_match_exactly($actual, array $expected): bool
    {
        if (! is_array($actual) || count($actual) !== count($expected)) {
            return false;
        }

        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual) || $actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function legacy_paragraph_matches(array $block, string $expected_text): bool
    {
        $html = isset($block['innerHTML']) && is_string($block['innerHTML']) ? $block['innerHTML'] : '';
        return $html === '<p>' . esc_html($expected_text) . '</p>';
    }

    private static function attachment_id_by_basename(string $basename): int
    {
        global $wpdb;

        $attachment_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND (meta_value = %s OR meta_value LIKE %s) ORDER BY post_id DESC LIMIT 1",
                $basename,
                '%/' . $wpdb->esc_like($basename)
            )
        );

        return valid_image_attachment_id($attachment_id);
    }

    private static function attachment_id_by_seed_key(string $seed_key): int
    {
        $ids = get_posts([
            'post_type'              => 'attachment',
            'post_status'            => 'inherit',
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'meta_key'               => self::SEED_META,
            'meta_value'             => $seed_key,
            'orderby'                => 'ID',
            'order'                  => 'DESC',
            'no_found_rows'          => true,
            'suppress_filters'       => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        return valid_image_attachment_id($ids[0] ?? 0);
    }

    private static function localized_source_media_url(string $source_url): string
    {
        $ids = get_posts([
            'post_type'              => 'attachment',
            'post_status'            => 'inherit',
            'posts_per_page'         => 10,
            'fields'                 => 'ids',
            'meta_key'               => '_goetz_source_media_url',
            'meta_value'             => $source_url,
            'orderby'                => 'ID',
            'order'                  => 'DESC',
            'no_found_rows'          => true,
            'suppress_filters'       => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        foreach ($ids as $id) {
            $attachment_id = valid_image_attachment_id($id);
            $url = $attachment_id > 0 ? wp_get_attachment_url($attachment_id) : false;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return $source_url;
    }

    private static function attachment_matches_seed(int $attachment_id, string $expected_hash): bool
    {
        if (valid_image_attachment_id($attachment_id) < 1) {
            return false;
        }

        $file = get_attached_file($attachment_id);
        return is_string($file) && self::file_matches_hash($file, $expected_hash);
    }

    private static function file_matches_hash(string $file, string $expected_hash): bool
    {
        return is_readable($file) && hash_file('sha256', $file) === $expected_hash;
    }

    private static function ensure_portrait_alt_text(int $attachment_id): void
    {
        if ((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true) === '') {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', 'James L. Goetz portrait');
        }
    }
}
