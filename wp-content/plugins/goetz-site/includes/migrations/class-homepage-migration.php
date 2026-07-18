<?php

declare(strict_types=1);

namespace Goetz\Site\Migrations;

use RuntimeException;
use WP_Post;

final class Homepage_Migration
{
    public const VERSION = 1;
    public const VERSION_OPTION = 'goetz_site_homepage_schema_version';
    public const PAGE_ID_OPTION = 'goetz_site_homepage_post_id_v1';
    public const LOCK_OPTION = 'goetz_site_homepage_migration_lock_v1';
    public const BACKUP_META = '_goetz_site_homepage_backup_v1';
    public const CONTENT_HASH_META = '_goetz_site_homepage_content_sha256_v1';

    /** @var array<string, mixed> */
    private array $config;

    private Media_Seeder $seeder;

    private Site_Bootstrap $bootstrap;

    /**
     * @param array<string, mixed>|null $config
     */
    public function __construct(
        ?array $config = null,
        ?Media_Seeder $seeder = null,
        ?Site_Bootstrap $bootstrap = null
    ) {
        $this->config = $config ?? Media_Seeder::load_config();
        $this->seeder = $seeder ?? new Media_Seeder($this->config);
        $this->bootstrap = $bootstrap ?? new Site_Bootstrap($this->config, $this->seeder);
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(): array
    {
        try {
            $post = $this->front_page();
        } catch (RuntimeException $exception) {
            return [
                'status'      => 'invalid_target',
                'post_id'     => (int) get_option('page_on_front'),
                'message'     => $exception->getMessage(),
                'block_order' => self::block_order(),
            ];
        }

        $state = $this->managed_state($post);
        $result = [
            'status'      => $state,
            'post_id'     => (int) $post->ID,
            'version'     => (int) get_option(self::VERSION_OPTION, 0),
            'block_order' => self::block_order(),
        ];

        if ($state === 'ready') {
            $result['media'] = $this->seeder->seed_all(true);
            $result['bootstrap'] = $this->bootstrap->plan();
            if (($result['bootstrap']['status'] ?? '') === 'blocked') {
                $result['status'] = 'blocked';
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(bool $force = false): array
    {
        $lock_token = $this->acquire_lock();
        try {
            return $this->apply_locked($force, $lock_token);
        } finally {
            $this->release_lock($lock_token);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function apply_locked(bool $force, string $lock_token): array
    {
        $post = $this->front_page();
        $state = $this->managed_state($post);
        if ($state === 'noop') {
            return ['status' => 'noop', 'post_id' => (int) $post->ID, 'version' => self::VERSION];
        }
        if ($state === 'managed_modified' && ! $force) {
            return ['status' => 'managed_modified', 'post_id' => (int) $post->ID, 'version' => self::VERSION];
        }
        if ($state === 'inconsistent' || $state === 'conflict' || $state === 'newer_version') {
            return ['status' => $state, 'post_id' => (int) $post->ID, 'version' => (int) get_option(self::VERSION_OPTION, 0)];
        }
        if ($state !== 'ready' && ! ($state === 'managed_modified' && $force)) {
            throw new RuntimeException("Homepage migration cannot apply from state {$state}.");
        }

        $journal = $this->snapshot($post);
        $this->assert_initial_fingerprint($post, $journal['fingerprint']);
        $seed_ids = [];
        $created_menu_ids = [];
        try {
            $seed_ids = $this->seeder->seed_all(false);
            $bootstrap = $this->bootstrap->apply();
            $created_menu_ids = array_map('intval', $bootstrap['created_menu_ids'] ?? []);
            foreach ([$journal['theme_option_name'], \Goetz\Site\Settings\Site_Settings::OPTION_NAME] as $option_name) {
                $after = $this->direct_option_snapshot($option_name);
                if ($after !== $journal['option_before'][$option_name]) {
                    $journal['owned_options'][$option_name] = $after;
                }
            }

            $blocks = $this->build_blocks($seed_ids);
            $content = serialize_blocks($blocks);
            if (serialize_blocks(parse_blocks($content)) !== $content) {
                throw new RuntimeException('Homepage block serialization did not round trip.');
            }

            $expected_fingerprint = $journal['fingerprint'];
            if ($expected_fingerprint['markers'][self::BACKUP_META]['rows'] === []) {
                $backup_meta_id = add_post_meta($post->ID, self::BACKUP_META, $post->post_content, true);
                if (! is_int($backup_meta_id) || $backup_meta_id < 1) {
                    throw new RuntimeException('Could not protect the original homepage content.');
                }
                $backup_after = $this->direct_post_meta_snapshot($post->ID, self::BACKUP_META);
                if (count($backup_after['rows']) !== 1
                    || (int) $backup_after['rows'][0]['meta_id'] !== $backup_meta_id
                    || $backup_after['rows'][0]['meta_value'] !== $post->post_content) {
                    throw new RuntimeException('Could not verify ownership of the homepage content backup.');
                }
                $journal['owned_meta'][self::BACKUP_META] = $backup_after;
                $expected_fingerprint['markers'][self::BACKUP_META] = $backup_after;
            }

            $this->assert_pre_update_fingerprint($post->ID, $expected_fingerprint, $lock_token);

            $capture_revision = static function ($revision_id, $revision) use (&$journal, $post): void {
                if ($revision instanceof WP_Post
                    && $revision->post_type === 'revision'
                    && (int) $revision->post_parent === (int) $post->ID) {
                    $journal['owned_revision_ids'][] = (int) $revision_id;
                }
            };
            add_action('wp_after_insert_post', $capture_revision, 10, 2);
            try {
                $updated = wp_update_post(wp_slash([
                    'ID'           => $post->ID,
                    'post_content' => $content,
                ]), true);
            } finally {
                remove_action('wp_after_insert_post', $capture_revision, 10);
            }
            if (is_wp_error($updated)) {
                throw new RuntimeException('Could not update the homepage: ' . $updated->get_error_message());
            }
            $stored_row = $this->direct_post_row($post->ID);
            $stored_content = $stored_row['post_content'];
            $stored_target_is_exact = $stored_content === $content
                && $stored_row['post_type'] === 'page'
                && $stored_row['post_name'] === 'home'
                && $stored_row['post_status'] === 'publish';
            if ($stored_target_is_exact) {
                $journal['owned_post'] = $stored_row;
            }
            if (! $stored_target_is_exact) {
                if ($stored_content === $content) {
                    throw new RuntimeException(
                        'Homepage target verification failed after update; type, slug, or publish status changed.'
                    );
                }
                $limit = min(strlen($stored_content), strlen($content));
                $offset = 0;
                while ($offset < $limit && $stored_content[$offset] === $content[$offset]) {
                    ++$offset;
                }
                throw new RuntimeException(
                    'Homepage content verification failed after update at byte '
                    . $offset . ' (expected ' . strlen($content) . ' bytes, stored '
                    . strlen($stored_content) . ' bytes).'
                );
            }

            $content_hash = hash('sha256', $content);
            $hash_before = $this->direct_post_meta_snapshot($post->ID, self::CONTENT_HASH_META);
            $this->write_post_meta($post->ID, self::CONTENT_HASH_META, $content_hash);
            $hash_after = $this->direct_post_meta_snapshot($post->ID, self::CONTENT_HASH_META);
            if ($hash_after !== $hash_before) {
                $journal['owned_meta'][self::CONTENT_HASH_META] = $hash_after;
            }
            $this->write_option(self::PAGE_ID_OPTION, (int) $post->ID);
            $journal['owned_options'][self::PAGE_ID_OPTION] = $this->direct_option_snapshot(self::PAGE_ID_OPTION);
            // The version is deliberately the final write in the migration.
            $this->write_option(self::VERSION_OPTION, self::VERSION);
            $journal['owned_options'][self::VERSION_OPTION] = $this->direct_option_snapshot(self::VERSION_OPTION);

            return [
                'status'    => $force ? 'recovered' : 'migrated',
                'post_id'   => (int) $post->ID,
                'version'   => self::VERSION,
                'media'     => $seed_ids,
                'bootstrap' => $bootstrap,
            ];
        } catch (\Throwable $exception) {
            $this->rollback($journal, $seed_ids, $created_menu_ids);
            throw $exception;
        }
    }

    /**
     * @param array<string, int> $attachment_ids
     * @return array<int, array<string, mixed>>
     */
    public function build_blocks(array $attachment_ids): array
    {
        $homepage = $this->config['homepage'] ?? null;
        $assets = $this->config['assets'] ?? null;
        if (! is_array($homepage) || ! is_array($assets)) {
            throw new RuntimeException('Canonical homepage configuration is missing.');
        }
        foreach (array_keys($assets) as $key) {
            if (! isset($attachment_ids[$key]) || ! is_int($attachment_ids[$key]) || $attachment_ids[$key] < 1) {
                throw new RuntimeException("Canonical homepage is missing attachment {$key}.");
            }
        }

        $lock = ['move' => true, 'remove' => true];
        $media = function (string $key) use ($attachment_ids, $assets): array {
            $attachment_id = $attachment_ids[$key];
            $url = wp_get_attachment_url($attachment_id);
            if (! is_string($url) || $url === '') {
                throw new RuntimeException("Could not resolve canonical media URL for {$key}.");
            }
            $path = wp_parse_url($url, PHP_URL_PATH);
            if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
                throw new RuntimeException("Canonical media URL for {$key} is not portable.");
            }

            return [
                'id'  => $attachment_id,
                'url' => $path,
                'alt' => (string) ($assets[$key]['alt'] ?? ''),
            ];
        };

        $hero_config = $this->section_config($homepage, 'hero');
        $hero_image = $media((string) $hero_config['image']);
        $hero = $this->block('goetz/hero', [
            'lock'         => $lock,
            'eyebrow'      => (string) $hero_config['eyebrow'],
            'heading'      => (string) $hero_config['heading'],
            'content'      => (string) $hero_config['content'],
            'imageUrl'     => $hero_image['url'],
            'imageAlt'     => $hero_image['alt'],
            'buttonText'   => (string) $hero_config['buttonText'],
            'buttonUrl'    => (string) $hero_config['buttonUrl'],
            'imageId'      => $hero_image['id'],
            'buttonNewTab' => (bool) $hero_config['buttonNewTab'],
        ]);

        $welcome_config = $this->section_config($homepage, 'welcome');
        $welcome_left = $media((string) $welcome_config['leftImage']);
        $welcome_right = $media((string) $welcome_config['rightImage']);
        $welcome = $this->block('goetz/welcome', [
            'lock'          => $lock,
            'leftImageId'   => $welcome_left['id'],
            'leftImageUrl'  => $welcome_left['url'],
            'leftImageAlt'  => $welcome_left['alt'],
            'rightImageId'  => $welcome_right['id'],
            'rightImageUrl' => $welcome_right['url'],
            'rightImageAlt' => $welcome_right['alt'],
            'heading'       => (string) $welcome_config['heading'],
            'contentPrefix' => (string) $welcome_config['contentPrefix'],
            'phoneLabel'    => (string) $welcome_config['phoneLabel'],
            'phoneUrl'      => (string) $welcome_config['phoneUrl'],
            'contentJoin'   => (string) $welcome_config['contentJoin'],
            'onlineLabel'   => (string) $welcome_config['onlineLabel'],
            'onlineUrl'     => (string) $welcome_config['onlineUrl'],
        ]);

        $practice_config = $this->section_config($homepage, 'practiceAreas');
        $practice_background = $media((string) $practice_config['backgroundImage']);
        $practice_scale = $media((string) $practice_config['scaleImage']);
        $practice_children = [];
        foreach ($practice_config['items'] as $label) {
            if (! is_string($label)) {
                throw new RuntimeException('Canonical Practice Area label is invalid.');
            }
            $practice_children[] = $this->block('goetz/practice-area-item', ['label' => $label]);
        }
        $practice = $this->block('goetz/practice-areas', [
            'lock'               => $lock,
            'heading'            => (string) $practice_config['heading'],
            'backgroundImageId'  => $practice_background['id'],
            'backgroundImageUrl' => $practice_background['url'],
            'backgroundImageAlt' => $practice_background['alt'],
            'scaleImageId'       => $practice_scale['id'],
            'scaleImageUrl'      => $practice_scale['url'],
            'scaleImageAlt'      => $practice_scale['alt'],
        ], $practice_children);

        $grid_config = $this->section_config($homepage, 'attorneyGrid');
        $attorney_children = [];
        foreach ($grid_config['attorneys'] as $attorney) {
            if (! is_array($attorney)) {
                throw new RuntimeException('Canonical Attorney Grid entry is invalid.');
            }
            $portrait = $media((string) $attorney['image']);
            $attorney_children[] = $this->block('goetz/attorney-card', [
                'name'          => (string) $attorney['name'],
                'bio'           => (string) $attorney['bio'],
                'imageUrl'      => $portrait['url'],
                'imageAlt'      => $portrait['alt'],
                'profileUrl'    => (string) $attorney['profileUrl'],
                'imageId'       => $portrait['id'],
                'profileNewTab' => false,
            ]);
        }
        $grid = $this->block('goetz/attorney-grid', [
            'lock'    => $lock,
            'heading' => (string) $grid_config['heading'],
        ], $attorney_children);

        $cta_config = $this->section_config($homepage, 'cta');
        $cta_background = $media((string) $cta_config['backgroundImage']);
        $cta = $this->block('goetz/cta', [
            'lock'               => $lock,
            'eyebrow'            => (string) $cta_config['eyebrow'],
            'heading'            => (string) $cta_config['heading'],
            'buttonText'         => (string) $cta_config['buttonText'],
            'buttonUrl'          => (string) $cta_config['buttonUrl'],
            'backgroundImageId'  => $cta_background['id'],
            'backgroundImageUrl' => $cta_background['url'],
            'buttonNewTab'       => (bool) $cta_config['buttonNewTab'],
        ]);

        $blocks = [$hero, $welcome, $practice, $grid, $cta];
        $content = serialize_blocks($blocks);
        if (serialize_blocks(parse_blocks($content)) !== $content) {
            throw new RuntimeException('Homepage block serialization did not round trip.');
        }

        return $blocks;
    }

    private function front_page(): WP_Post
    {
        $post_id = (int) get_option('page_on_front');
        $post = get_post($post_id);
        if (get_option('show_on_front') !== 'page'
            || ! $post instanceof WP_Post
            || $post->post_type !== 'page'
            || $post->post_name !== 'home'
            || $post->post_status !== 'publish') {
            throw new RuntimeException('Configured page_on_front must target a published page with slug home before writes.');
        }

        return $post;
    }

    private function managed_state(WP_Post $post): string
    {
        $version_snapshot = $this->snapshot_option(self::VERSION_OPTION);
        $page_snapshot = $this->snapshot_option(self::PAGE_ID_OPTION);
        $version = $version_snapshot['exists'] ? absint($version_snapshot['value']) : 0;
        if ($version > self::VERSION) {
            return 'newer_version';
        }
        if ($version === self::VERSION) {
            $hash_values = get_post_meta($post->ID, self::CONTENT_HASH_META, false);
            $backup_values = get_post_meta($post->ID, self::BACKUP_META, false);
            if (! $page_snapshot['exists']
                || (int) $page_snapshot['value'] !== (int) $post->ID
                || count($backup_values) !== 1
                || ! is_string($backup_values[0])
                || count($hash_values) !== 1
                || ! is_string($hash_values[0])
                || preg_match('/^[a-f0-9]{64}$/', $hash_values[0]) !== 1) {
                return 'inconsistent';
            }

            return hash('sha256', $post->post_content) === $hash_values[0]
                ? 'noop'
                : 'managed_modified';
        }
        if ($version_snapshot['exists']
            || $page_snapshot['exists']
            || metadata_exists('post', $post->ID, self::BACKUP_META)
            || metadata_exists('post', $post->ID, self::CONTENT_HASH_META)) {
            return 'inconsistent';
        }

        return $this->matches_known_legacy($post->post_content) ? 'ready' : 'conflict';
    }

    private function matches_known_legacy(string $content): bool
    {
        return $this->normalize_legacy_content($content) === $this->legacy_template();
    }

    private function normalize_legacy_content(string $content): string
    {
        $filenames = [];
        foreach ($this->config['assets'] ?? [] as $key => $asset) {
            if (is_array($asset) && isset($asset['filename']) && is_string($asset['filename'])) {
                $filenames[$asset['filename']] = (string) $key;
            }
        }
        $home = wp_parse_url(home_url('/'));

        return (string) preg_replace_callback(
            '#https?://[^"<\\s]+#',
            static function (array $match) use ($filenames, $home): string {
                $url = html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $parts = wp_parse_url($url);
                if (! is_array($parts)
                    || ! is_array($home)
                    || isset($parts['user'])
                    || isset($parts['pass'])
                    || isset($parts['query'])
                    || isset($parts['fragment'])
                    || strtolower((string) ($parts['scheme'] ?? '')) !== strtolower((string) ($home['scheme'] ?? ''))
                    || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($home['host'] ?? ''))
                    || (int) ($parts['port'] ?? 0) !== (int) ($home['port'] ?? 0)) {
                    return $match[0];
                }
                $path = (string) ($parts['path'] ?? '');
                foreach ([
                    '/james-l-goetz/'   => '{{route_james}}',
                    '/gregory-w-goetz/' => '{{route_gregory}}',
                    '/contact/'         => '{{route_contact}}',
                ] as $route => $token) {
                    if ($path === $route) {
                        return $token;
                    }
                }
                foreach ($filenames as $filename => $key) {
                    $quoted = preg_quote($filename, '#');
                    $scaled = preg_replace('/(\.[^.]+)$/', '-scaled$1', $filename);
                    if (preg_match('#^/wp-content/uploads/\d{4}/\d{2}/(?:' . $quoted . '|' . preg_quote((string) $scaled, '#') . ')$#', $path) === 1) {
                        return '{{asset_' . $key . '}}';
                    }
                }

                return $match[0];
            },
            $content
        );
    }

    private function legacy_template(): string
    {
        $homepage = $this->config['homepage'];
        $hero = $homepage['hero'];
        $welcome = $homepage['welcome'];
        $practice = $homepage['practiceAreas'];
        $grid = $homepage['attorneyGrid'];
        $hero_attrs = [
            'eyebrow'    => $hero['eyebrow'],
            'heading'    => $hero['heading'],
            'content'    => $hero['content'],
            'imageUrl'   => '{{asset_hero_exterior}}',
            'imageAlt'   => 'Goetz Legal exterior',
            'buttonText' => $hero['buttonText'],
            'buttonUrl'  => '{{route_james}}',
        ];
        $intro = '<section class="goetz-intro-section"><div class="goetz-intro">'
            . '<img class="goetz-intro__image" src="{{asset_welcome_left}}" alt="James L. Goetz recognition plaque" loading="lazy">'
            . '<div class="goetz-intro__content"><h2>' . $welcome['heading'] . '</h2>'
            . '<img class="goetz-intro__icon" src="{{asset_scale_icon}}" alt="" loading="lazy">'
            . '<p>If you would like to speak with Mr. Goetz, please call <strong>(239) 936-2841</strong> or contact the firm <a href="{{route_contact}}">online</a>.</p>'
            . '</div><img class="goetz-intro__image" src="{{asset_welcome_right}}" alt="Goetz Legal office library photo" loading="lazy">'
            . '</div></section>';
        $practice_html = '<section class="goetz-practice-band"><div class="goetz-practice-band__image">'
            . '<img src="{{asset_practice_bg}}" alt="Law office books and desk" loading="lazy"></div>'
            . '<div class="goetz-practice-band__content"><h2>' . $practice['heading'] . '</h2><ul class="goetz-practice-list">';
        foreach ($practice['items'] as $label) {
            $practice_html .= '<li><span aria-hidden="true"><img src="{{asset_scale_icon}}" alt="" loading="lazy"></span><b>'
                . esc_html((string) $label) . '</b></li>';
        }
        $practice_html .= '</ul></div></section>';

        $legacy_james_bio = str_replace('father’s', "father's", (string) $grid['attorneys'][0]['bio']);
        $cards = [
            [
                'name'       => $grid['attorneys'][0]['name'],
                'bio'        => $legacy_james_bio,
                'imageUrl'   => '{{asset_james_card}}',
                'imageAlt'   => 'James L. Goetz',
                'profileUrl' => '{{route_james}}',
            ],
            [
                'name'       => $grid['attorneys'][1]['name'],
                'bio'        => $grid['attorneys'][1]['bio'],
                'imageUrl'   => '{{asset_gregory_card}}',
                'imageAlt'   => 'Gregory W. Goetz',
                'profileUrl' => '{{route_gregory}}',
            ],
        ];
        $group = '<!-- wp:group {"className":"goetz-section goetz-section--attorneys","layout":{"type":"constrained"}} -->'
            . '<div class="wp-block-group goetz-section goetz-section--attorneys">'
            . '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Attorneys</h2><!-- /wp:heading -->'
            . '<div class="goetz-card-grid">'
            . $this->legacy_self_closing_block('goetz/attorney-card', $cards[0])
            . $this->legacy_self_closing_block('goetz/attorney-card', $cards[1])
            . '</div></div><!-- /wp:group -->';

        return $this->legacy_self_closing_block('goetz/hero', $hero_attrs)
            . '<!-- wp:html -->' . $intro . '<!-- /wp:html -->'
            . '<!-- wp:html -->' . $practice_html . '<!-- /wp:html -->'
            . $group
            . '<!-- wp:goetz/cta /-->';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function legacy_self_closing_block(string $name, array $attrs): string
    {
        return '<!-- wp:' . $name . ' '
            . wp_json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . ' /-->';
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<int, array<string, mixed>> $children
     * @return array<string, mixed>
     */
    private function block(string $name, array $attrs, array $children = []): array
    {
        return [
            'blockName'    => $name,
            'attrs'        => $attrs,
            'innerBlocks'  => $children,
            'innerHTML'    => '',
            'innerContent' => array_fill(0, count($children), null),
        ];
    }

    /**
     * @param array<string, mixed> $homepage
     * @return array<string, mixed>
     */
    private function section_config(array $homepage, string $key): array
    {
        if (! isset($homepage[$key]) || ! is_array($homepage[$key])) {
            throw new RuntimeException("Canonical homepage section {$key} is invalid.");
        }

        return $homepage[$key];
    }

    /**
     * @return array<int, string>
     */
    private static function block_order(): array
    {
        return ['goetz/hero', 'goetz/welcome', 'goetz/practice-areas', 'goetz/attorney-grid', 'goetz/cta'];
    }

    /**
     * @return array{exists: bool, value: mixed}
     */
    private function snapshot_option(string $name): array
    {
        $sentinel = '__goetz_homepage_missing_' . wp_generate_uuid4();
        $value = get_option($name, $sentinel);

        return ['exists' => $value !== $sentinel, 'value' => $value];
    }

    private function acquire_lock(): string
    {
        $token = wp_generate_uuid4();
        if (! add_option(self::LOCK_OPTION, $token, '', false)) {
            throw new RuntimeException('Homepage migration is already running.');
        }

        $lock = $this->direct_option_snapshot(self::LOCK_OPTION);
        if (! $lock['exists'] || $lock['option_value'] !== $token) {
            $this->release_lock($token);
            throw new RuntimeException('Could not verify ownership of the homepage migration lock.');
        }

        return $token;
    }

    private function release_lock(string $token): void
    {
        global $wpdb;

        $lock = $this->direct_option_snapshot(self::LOCK_OPTION);
        if (! $lock['exists'] || $lock['option_value'] !== $token) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                    WHERE option_id = %d AND option_name = %s AND BINARY option_value = BINARY %s",
                $lock['option_id'],
                self::LOCK_OPTION,
                $token
            )
        );
        $this->flush_option_cache(self::LOCK_OPTION, false);
    }

    /**
     * @return array{option_id: int, exists: bool, option_value: ?string}
     */
    private function direct_option_snapshot(string $name): array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT option_id, option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $name
            ),
            ARRAY_A
        );
        if (! is_array($row)) {
            return ['option_id' => 0, 'exists' => false, 'option_value' => null];
        }

        return [
            'option_id'    => (int) $row['option_id'],
            'exists'       => true,
            'option_value' => (string) $row['option_value'],
        ];
    }

    /**
     * @return array{rows: array<int, array{meta_id: int, meta_value: string}>}
     */
    private function direct_post_meta_snapshot(int $post_id, string $key): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
                $post_id,
                $key
            ),
            ARRAY_A
        );

        return [
            'rows' => array_map(
                static fn(array $row): array => [
                    'meta_id'    => (int) $row['meta_id'],
                    'meta_value' => (string) $row['meta_value'],
                ],
                is_array($rows) ? $rows : []
            ),
        ];
    }

    /**
     * @return array{post_content: string, post_type: string, post_name: string, post_status: string, post_modified: string, post_modified_gmt: string}
     */
    private function direct_post_row(int $post_id): array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT post_content, post_type, post_name, post_status, post_modified, post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
                $post_id
            ),
            ARRAY_A
        );
        if (! is_array($row)) {
            throw new RuntimeException('Configured homepage disappeared during migration.');
        }

        return [
            'post_content'      => (string) $row['post_content'],
            'post_type'         => (string) $row['post_type'],
            'post_name'         => (string) $row['post_name'],
            'post_status'       => (string) $row['post_status'],
            'post_modified'     => (string) $row['post_modified'],
            'post_modified_gmt' => (string) $row['post_modified_gmt'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function direct_fingerprint(int $post_id): array
    {
        $post = $this->direct_post_row($post_id);

        return [
            'post' => [
                'post_content' => $post['post_content'],
                'post_type'    => $post['post_type'],
                'post_name'    => $post['post_name'],
                'post_status'  => $post['post_status'],
            ],
            'front' => [
                'show_on_front' => $this->direct_option_snapshot('show_on_front'),
                'page_on_front' => $this->direct_option_snapshot('page_on_front'),
            ],
            'markers' => [
                self::BACKUP_META       => $this->direct_post_meta_snapshot($post_id, self::BACKUP_META),
                self::CONTENT_HASH_META => $this->direct_post_meta_snapshot($post_id, self::CONTENT_HASH_META),
                self::VERSION_OPTION    => $this->direct_option_snapshot(self::VERSION_OPTION),
                self::PAGE_ID_OPTION    => $this->direct_option_snapshot(self::PAGE_ID_OPTION),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $fingerprint
     */
    private function assert_initial_fingerprint(WP_Post $post, array $fingerprint): void
    {
        $expected_post = [
            'post_content' => $post->post_content,
            'post_type'    => $post->post_type,
            'post_name'    => $post->post_name,
            'post_status'  => $post->post_status,
        ];
        $show = $fingerprint['front']['show_on_front'] ?? [];
        $front = $fingerprint['front']['page_on_front'] ?? [];
        if (($fingerprint['post'] ?? null) !== $expected_post
            || ! ($show['exists'] ?? false)
            || ($show['option_value'] ?? null) !== 'page'
            || ! ($front['exists'] ?? false)
            || (int) ($front['option_value'] ?? 0) !== (int) $post->ID) {
            throw new RuntimeException('Homepage target changed before migration preparation began.');
        }
    }

    /**
     * @param array<string, mixed> $expected
     */
    private function assert_pre_update_fingerprint(int $post_id, array $expected, string $lock_token): void
    {
        $lock = $this->direct_option_snapshot(self::LOCK_OPTION);
        if (! $lock['exists'] || $lock['option_value'] !== $lock_token) {
            throw new RuntimeException('Homepage migration lost ownership of its lock.');
        }
        if ($this->direct_fingerprint($post_id) !== $expected) {
            throw new RuntimeException('Homepage changed while the migration was preparing its update.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(WP_Post $post): array
    {
        $attachment_ids = array_map('intval', get_posts([
            'post_type'              => 'attachment',
            'post_status'            => 'inherit',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'suppress_filters'       => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]));
        $attachment_meta = [];
        foreach ($attachment_ids as $id) {
            $attachment_meta[$id] = [
                'seed'     => get_post_meta($id, Media_Seeder::META_KEY, false),
                'checksum' => get_post_meta($id, Media_Seeder::CHECKSUM_META_KEY, false),
            ];
        }
        $theme_option = 'theme_mods_' . get_option('stylesheet');
        $fingerprint = $this->direct_fingerprint((int) $post->ID);

        return [
            'post_id'            => (int) $post->ID,
            'post_before'        => $this->direct_post_row((int) $post->ID),
            'fingerprint'        => $fingerprint,
            'meta_before'        => [
                self::BACKUP_META      => $fingerprint['markers'][self::BACKUP_META],
                self::CONTENT_HASH_META => $fingerprint['markers'][self::CONTENT_HASH_META],
            ],
            'theme_option_name'  => $theme_option,
            'option_before'      => [
                self::VERSION_OPTION => $fingerprint['markers'][self::VERSION_OPTION],
                self::PAGE_ID_OPTION => $fingerprint['markers'][self::PAGE_ID_OPTION],
                $theme_option => $this->direct_option_snapshot($theme_option),
                \Goetz\Site\Settings\Site_Settings::OPTION_NAME => $this->direct_option_snapshot(
                    \Goetz\Site\Settings\Site_Settings::OPTION_NAME
                ),
            ],
            'owned_post'         => null,
            'owned_meta'         => [],
            'owned_options'      => [],
            'owned_revision_ids' => [],
            'attachment_ids'     => $attachment_ids,
            'attachment_meta'    => $attachment_meta,
        ];
    }

    /**
     * @param array<string, mixed> $journal
     * @param array<string, int> $seed_ids
     * @param array<int, int> $created_menu_ids
     */
    private function rollback(array $journal, array $seed_ids, array $created_menu_ids): void
    {
        $post_id = (int) $journal['post_id'];
        if (is_array($journal['owned_post'] ?? null)) {
            $this->restore_owned_post($post_id, $journal['post_before'], $journal['owned_post']);
        }

        foreach (array_unique(array_map('intval', $journal['owned_revision_ids'] ?? [])) as $revision_id) {
            $revision = get_post($revision_id);
            if ($revision instanceof WP_Post
                && $revision->post_type === 'revision'
                && (int) $revision->post_parent === $post_id) {
                wp_delete_post($revision_id, true);
            }
        }

        foreach ($journal['owned_meta'] ?? [] as $key => $after) {
            if (isset($journal['meta_before'][$key]) && is_array($after)) {
                $this->restore_owned_post_meta($post_id, (string) $key, $journal['meta_before'][$key], $after);
            }
        }
        foreach ($journal['owned_options'] ?? [] as $name => $after) {
            if (isset($journal['option_before'][$name]) && is_array($after)) {
                $this->restore_owned_option((string) $name, $journal['option_before'][$name], $after);
            }
        }

        foreach (array_unique($created_menu_ids) as $menu_id) {
            if ($menu_id > 0) {
                wp_delete_nav_menu($menu_id);
            }
        }

        $before_lookup = array_fill_keys($journal['attachment_ids'], true);
        foreach (array_unique(array_map('intval', $seed_ids)) as $attachment_id) {
            if ($attachment_id < 1) {
                continue;
            }
            if (! isset($before_lookup[$attachment_id])) {
                wp_delete_attachment($attachment_id, true);
                continue;
            }
            $meta = $journal['attachment_meta'][$attachment_id] ?? ['seed' => [], 'checksum' => []];
            $this->restore_post_meta($attachment_id, Media_Seeder::META_KEY, $meta['seed']);
            $this->restore_post_meta($attachment_id, Media_Seeder::CHECKSUM_META_KEY, $meta['checksum']);
        }
    }

    /**
     * @param array<int, mixed> $values
     */
    private function restore_post_meta(int $post_id, string $key, array $values): void
    {
        delete_post_meta($post_id, $key);
        foreach ($values as $value) {
            add_post_meta($post_id, $key, $value);
        }
    }

    /**
     * @param array<string, string> $before
     * @param array<string, string> $owned_after
     */
    private function restore_owned_post(int $post_id, array $before, array $owned_after): void
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->posts}
                SET post_content = %s, post_modified = %s, post_modified_gmt = %s
                WHERE ID = %d
                  AND BINARY post_content = BINARY %s
                  AND post_type = %s
                  AND post_name = %s
                  AND post_status = %s
                  AND post_modified = %s
                  AND post_modified_gmt = %s",
            $before['post_content'],
            $before['post_modified'],
            $before['post_modified_gmt'],
            $post_id,
            $owned_after['post_content'],
            $owned_after['post_type'],
            $owned_after['post_name'],
            $owned_after['post_status'],
            $owned_after['post_modified'],
            $owned_after['post_modified_gmt']
        );
        $updated = $wpdb->query($sql);
        if ($updated === 1) {
            clean_post_cache($post_id);
        }
    }

    /**
     * @param array{rows: array<int, array{meta_id: int, meta_value: string}>} $before
     * @param array{rows: array<int, array{meta_id: int, meta_value: string}>} $owned_after
     */
    private function restore_owned_post_meta(int $post_id, string $key, array $before, array $owned_after): void
    {
        global $wpdb;

        if ($this->direct_post_meta_snapshot($post_id, $key) !== $owned_after) {
            return;
        }
        $before_rows = $before['rows'];
        $after_rows = $owned_after['rows'];
        if ($before_rows === [] && count($after_rows) === 1) {
            $row = $after_rows[0];
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->postmeta}
                        WHERE meta_id = %d AND post_id = %d AND meta_key = %s
                          AND BINARY meta_value = BINARY %s",
                    $row['meta_id'],
                    $post_id,
                    $key,
                    $row['meta_value']
                )
            );
            wp_cache_delete($post_id, 'post_meta');
            return;
        }
        if (count($before_rows) === 1
            && count($after_rows) === 1
            && $before_rows[0]['meta_id'] === $after_rows[0]['meta_id']) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} SET meta_value = %s
                        WHERE meta_id = %d AND post_id = %d AND meta_key = %s
                          AND BINARY meta_value = BINARY %s",
                    $before_rows[0]['meta_value'],
                    $after_rows[0]['meta_id'],
                    $post_id,
                    $key,
                    $after_rows[0]['meta_value']
                )
            );
            wp_cache_delete($post_id, 'post_meta');
        }
    }

    /**
     * @param array{option_id: int, exists: bool, option_value: ?string} $before
     * @param array{option_id: int, exists: bool, option_value: ?string} $owned_after
     */
    private function restore_owned_option(string $name, array $before, array $owned_after): void
    {
        global $wpdb;

        if ($this->direct_option_snapshot($name) !== $owned_after) {
            return;
        }
        if (! $before['exists'] && $owned_after['exists']) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options}
                        WHERE option_id = %d AND option_name = %s
                          AND BINARY option_value = BINARY %s",
                    $owned_after['option_id'],
                    $name,
                    $owned_after['option_value']
                )
            );
            $this->flush_option_cache($name);
            return;
        }
        if ($before['exists']
            && $owned_after['exists']
            && $before['option_id'] === $owned_after['option_id']) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->options} SET option_value = %s
                        WHERE option_id = %d AND option_name = %s
                          AND BINARY option_value = BINARY %s",
                    $before['option_value'],
                    $owned_after['option_id'],
                    $name,
                    $owned_after['option_value']
                )
            );
            $this->flush_option_cache($name);
        }
    }

    private function flush_option_cache(string $name, bool $flush_alloptions = true): void
    {
        wp_cache_delete($name, 'options');
        if ($flush_alloptions) {
            wp_cache_delete('alloptions', 'options');
        }
        wp_cache_delete('notoptions', 'options');
    }

    private function write_post_meta(int $post_id, string $key, string $value): void
    {
        update_post_meta($post_id, $key, $value);
        $stored = get_post_meta($post_id, $key, false);
        if ($stored !== [$value]) {
            throw new RuntimeException("Could not persist homepage migration metadata {$key}.");
        }
    }

    private function write_option(string $name, int $value): void
    {
        update_option($name, $value);
        $stored = $this->direct_option_snapshot($name);
        if (! $stored['exists'] || (int) $stored['option_value'] !== $value) {
            throw new RuntimeException("Could not persist homepage migration option {$name}.");
        }
    }
}
