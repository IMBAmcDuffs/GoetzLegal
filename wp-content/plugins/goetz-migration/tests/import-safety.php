<?php

if (! defined('ABSPATH')) {
    fwrite(STDERR, "import-safety.php must run through WP-CLI.\n");
    exit(1);
}

/**
 * @param bool $condition
 */
function goetz_migration_safety_assert($condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function goetz_migration_safety_is_loopback_url(string $url): bool
{
    $host = trim(strtolower((string) wp_parse_url($url, PHP_URL_HOST)), '[]');

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

goetz_migration_safety_assert(
    getenv('GOETZ_ALLOW_MUTATING_TESTS') === '1',
    'Importer safety fixtures require GOETZ_ALLOW_MUTATING_TESTS=1.'
);
goetz_migration_safety_assert(
    in_array(wp_get_environment_type(), ['local', 'development', 'test'], true),
    'Importer safety fixtures require a local, development, or test WordPress environment.'
);
goetz_migration_safety_assert(
    goetz_migration_safety_is_loopback_url(home_url('/'))
        && goetz_migration_safety_is_loopback_url(site_url('/')),
    'Importer safety fixtures require both home_url and site_url to use a loopback host.'
);

goetz_migration_safety_assert(
    method_exists(Goetz_Migration_Scraper::class, 'plan_pages'),
    'The read-only import planner is missing.'
);
goetz_migration_safety_assert(
    method_exists(Goetz_Migration_Scraper::class, 'apply_plan'),
    'The importer does not expose a separately reviewable apply phase.'
);
goetz_migration_safety_assert(
    method_exists(Goetz_Migration_Scraper::class, 'discover_site'),
    'Source discovery is not separated from writes.'
);
goetz_migration_safety_assert(
    function_exists('goetz_migration_handle_admin_request'),
    'The capability- and nonce-guarded admin request handler is missing.'
);
goetz_migration_safety_assert(
    method_exists(Goetz_Migration_CLI::class, 'force_confirmation_required'),
    'The CLI force-confirmation policy is not explicit and testable.'
);
goetz_migration_safety_assert(
    method_exists(Goetz_Migration_CLI::class, 'import_has_errors'),
    'The CLI does not expose a deterministic nonzero-exit policy for apply errors.'
);
goetz_migration_safety_assert(
    method_exists(Goetz_Migration_Scraper::class, 'track_owned_attachment')
        && method_exists(Goetz_Migration_Scraper::class, 'update_page_content'),
    'The importer does not expose protected media-journal and page-update seams.'
);

/**
 * Deterministic test double: discovery and content construction are local, and
 * the call log proves existing pages are skipped before expensive work.
 */
final class Goetz_Migration_Safety_Scraper extends Goetz_Migration_Scraper
{
    /** @var array<int, array<string, mixed>> */
    private array $fixtures;

    /** @var array<int, string> */
    public array $built_slugs = [];

    /** @var array<int, string> */
    public array $localized_slugs = [];

    public string $mutate_during_localize_slug = '';

    public string $concurrent_editor_content = '<!-- wp:paragraph --><p>Concurrent editor save.</p><!-- /wp:paragraph -->';

    public string $create_owned_media_slug = '';

    public string $use_parent_localizer_slug = '';

    public string $fail_update_slug = '';

    /** @var array<int, int> */
    public array $owned_attachment_ids = [];

    /** @var array<int, string> */
    public array $owned_attachment_files = [];

    /**
     * @param array<int, array<string, mixed>> $fixtures
     */
    public function __construct(array $fixtures)
    {
        $this->fixtures = $fixtures;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function discover_site(string $source_url = 'https://goetzlegal.com'): array
    {
        return $this->fixtures;
    }

    /**
     * @param array<string, mixed> $page
     */
    protected function build_page_content(string $slug, array $page, int $post_id): string
    {
        $this->built_slugs[] = $slug;

        return (string) ($page['fixture_content'] ?? '');
    }

    protected function localize_remote_media(string $content, int $post_id): string
    {
        $post = get_post($post_id);
        $slug = $post instanceof WP_Post ? $post->post_name : '';
        $this->localized_slugs[] = $slug;

        if ($slug !== '' && $slug === $this->use_parent_localizer_slug) {
            $this->use_parent_localizer_slug = '';
            $content = parent::localize_remote_media($content, $post_id);
        }

        if ($slug !== '' && $slug === $this->create_owned_media_slug) {
            $this->create_owned_media_slug = '';
            $upload = wp_upload_dir();
            goetz_migration_safety_assert(empty($upload['error']), 'Could not resolve the upload fixture directory.');
            goetz_migration_safety_assert(
                wp_mkdir_p((string) $upload['path']),
                'Could not create the upload fixture directory.'
            );
            $file = trailingslashit((string) $upload['path'])
                . 'goetz-import-owned-' . wp_generate_uuid4() . '.txt';
            goetz_migration_safety_assert(
                file_put_contents($file, 'Goetz importer owned media fixture') !== false,
                'Could not write the owned media fixture.'
            );
            $attachment_id = wp_insert_attachment([
                'post_mime_type' => 'text/plain',
                'post_status'    => 'inherit',
                'post_title'     => 'Goetz importer owned media fixture',
            ], $file, $post_id, true);
            if (is_wp_error($attachment_id)) {
                wp_delete_file($file);
                throw new RuntimeException('Could not seed the owned attachment fixture.');
            }

            $attachment_id = (int) $attachment_id;
            update_attached_file($attachment_id, $file);
            $this->track_owned_attachment($attachment_id);
            $this->owned_attachment_ids[] = $attachment_id;
            $this->owned_attachment_files[] = $file;
            $content .= '<!-- owned media localized -->';
        }

        if ($slug !== '' && $slug === $this->mutate_during_localize_slug) {
            $this->mutate_during_localize_slug = '';
            wp_update_post([
                'ID'           => $post_id,
                'post_content' => $this->concurrent_editor_content,
            ]);
        }

        return $content;
    }

    /**
     * @return int|WP_Error
     */
    protected function update_page_content(int $post_id, string $content)
    {
        $post = get_post($post_id);
        if ($post instanceof WP_Post && $post->post_name === $this->fail_update_slug) {
            $this->fail_update_slug = '';

            return new WP_Error('goetz_test_update_failed', 'Simulated page update failure.');
        }

        return parent::update_page_content($post_id, $content);
    }
}

/**
 * @return array{exists: bool, value: mixed}
 */
function goetz_migration_safety_option_snapshot(string $name): array
{
    $sentinel = '__goetz_migration_missing_' . wp_generate_uuid4();
    $value = get_option($name, $sentinel);
    $exists = $value !== $sentinel;

    return [
        'exists' => $exists,
        'value'  => $exists ? $value : null,
    ];
}

/**
 * Hash the persistent state a dry-run is forbidden to change.
 */
function goetz_migration_safety_persistent_fingerprint(): string
{
    global $wpdb;

    $queries = [
        "SELECT * FROM {$wpdb->posts} ORDER BY ID",
        "SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id",
        "SELECT * FROM {$wpdb->terms} ORDER BY term_id",
        "SELECT * FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id",
        "SELECT * FROM {$wpdb->term_relationships} ORDER BY object_id, term_taxonomy_id",
        "SELECT * FROM {$wpdb->termmeta} ORDER BY meta_id",
        "SELECT * FROM {$wpdb->options} ORDER BY option_id",
    ];
    $state = [];

    foreach ($queries as $query) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static test-only table queries.
        $state[] = $wpdb->get_results($query, ARRAY_A);
    }

    return hash('sha256', (string) wp_json_encode($state));
}

/**
 * @param array<string, mixed> $plan
 * @return array<string, mixed>
 */
function goetz_migration_safety_plan_item(array $plan, string $slug): array
{
    foreach ($plan['items'] ?? [] as $item) {
        if (($item['slug'] ?? '') === $slug) {
            return $item;
        }
    }

    throw new RuntimeException("Import plan omitted fixture slug: {$slug}");
}

/**
 * @return array<string, mixed>
 */
function goetz_migration_safety_post_snapshot(int $post_id): array
{
    $post = get_post($post_id, ARRAY_A);

    return [
        'post' => $post,
        'meta' => get_post_meta($post_id),
    ];
}

$suffix = strtolower(wp_generate_password(10, false, false));
$existing_slug = 'goetz-import-safety-existing-' . $suffix;
$missing_slug = 'goetz-import-safety-missing-' . $suffix;
$admin_missing_slug = 'goetz-import-safety-admin-' . $suffix;
$failure_slug = 'goetz-import-safety-failure-' . $suffix;
$existing_content = '<!-- wp:paragraph {"className":"editor-preserved"} -->'
    . '<p class="editor-preserved">Editor-authored content must survive.</p>'
    . '<!-- /wp:paragraph -->';
$replacement_content = '<!-- wp:group {"layout":{"type":"constrained"}} -->'
    . '<div class="wp-block-group"><!-- wp:heading {"level":2} -->'
    . '<h2 class="wp-block-heading">Imported replacement</h2><!-- /wp:heading -->'
    . '<!-- wp:paragraph --><p>Source copy for review.</p><!-- /wp:paragraph -->'
    . '</div><!-- /wp:group -->';
$missing_content = '<!-- wp:paragraph --><p>Missing page source copy.</p><!-- /wp:paragraph -->'
    . '<!-- wp:image {"url":"https://goetzlegal.com/wp-content/uploads/safety-fixture.jpg"} -->'
    . '<figure class="wp-block-image"><img src="https://goetzlegal.com/wp-content/uploads/safety-fixture.jpg" alt="Safety fixture"></figure>'
    . '<!-- /wp:image -->';

$fixtures = [
    [
        'title'           => 'Imported Existing Title',
        'slug'            => $existing_slug,
        'path'            => '/' . $existing_slug . '/',
        'url'             => 'https://goetzlegal.com/' . $existing_slug . '/',
        'raw_content'     => '<p>Remote existing source.</p>',
        'fixture_content' => $replacement_content,
        'method'          => 'fixture',
        'modified'        => '2026-07-17T00:00:00',
        'yoast'           => [
            'title'       => 'Imported SEO title',
            'description' => 'Imported SEO description',
            'canonical'   => 'https://goetzlegal.com/' . $existing_slug . '/',
        ],
        'media_count'     => 0,
        'source_hash'     => hash('sha256', $replacement_content),
    ],
    [
        'title'           => 'Missing Safety Page',
        'slug'            => $missing_slug,
        'path'            => '/' . $missing_slug . '/',
        'url'             => 'https://goetzlegal.com/' . $missing_slug . '/',
        'raw_content'     => '<p>Remote missing source.</p>',
        'fixture_content' => $missing_content,
        'method'          => 'fixture',
        'modified'        => '2026-07-17T00:00:00',
        'yoast'           => [
            'title'       => 'Do not import this title',
            'description' => 'Do not import this description',
            'canonical'   => 'https://goetzlegal.com/' . $missing_slug . '/',
        ],
        'media_count'     => 1,
        'source_hash'     => hash('sha256', $missing_content),
    ],
];

$scraper = new Goetz_Migration_Safety_Scraper($fixtures);
$initial_user_id = get_current_user_id();
$created_ids = [];
$existing_id = 0;
$menu_id = 0;
$menu_item_id = 0;
$reused_attachment_id = 0;
$reused_attachment_file = '';
$cache_snapshot_taken = false;
$cache_found_before = false;
$cache_value_before = null;
$original_cache_snapshot_taken = false;
$original_cache_found = false;
$original_cache_value = null;
$cache_restore_verified = false;

try {
    $original_cache_value = wp_cache_get('scan_data', 'goetz-migration', false, $original_cache_found);
    $original_cache_snapshot_taken = true;
    wp_cache_set('scan_data', ['fixture' => $suffix], 'goetz-migration');

    $seeded_existing_id = wp_insert_post([
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => 'Editor Preserved Title',
        'post_name'    => $existing_slug,
        'post_content' => $existing_content,
    ], true);
    goetz_migration_safety_assert(
        ! is_wp_error($seeded_existing_id),
        'Could not seed the existing-page safety fixture.'
    );
    $existing_id = (int) $seeded_existing_id;

    update_post_meta($existing_id, '_wp_page_template', 'page-templates/template-contact.php');
    update_post_meta($existing_id, '_yoast_wpseo_title', 'Editor SEO title');
    update_post_meta($existing_id, '_yoast_wpseo_metadesc', 'Editor SEO description');
    update_post_meta($existing_id, '_yoast_wpseo_canonical', 'https://editor.example.test/preserved/');
    update_post_meta($existing_id, '_yoast_wpseo_focuskw', 'editor keyword');

    $seeded_menu_id = wp_create_nav_menu('Goetz import safety ' . $suffix);
    goetz_migration_safety_assert(! is_wp_error($seeded_menu_id), 'Could not seed the menu safety fixture.');
    $menu_id = (int) $seeded_menu_id;
    $seeded_menu_item_id = wp_update_nav_menu_item($menu_id, 0, [
        'menu-item-title'     => 'Editor Menu Label',
        'menu-item-object-id' => $existing_id,
        'menu-item-object'    => 'page',
        'menu-item-type'      => 'post_type',
        'menu-item-status'    => 'publish',
    ]);
    goetz_migration_safety_assert(
        ! is_wp_error($seeded_menu_item_id),
        'Could not seed the menu-item safety fixture.'
    );
    $menu_item_id = (int) $seeded_menu_item_id;

    $existing_snapshot = goetz_migration_safety_post_snapshot($existing_id);
    $menu_snapshot = get_post($menu_item_id);
    $site_options = [];
    foreach ([
        'show_on_front',
        'page_on_front',
        'page_for_posts',
        'goetz_migration_scan_data',
        'goetz_migration_source_url',
        'goetz_migration_fetch_proxy_url',
    ] as $option_name) {
        $site_options[$option_name] = goetz_migration_safety_option_snapshot($option_name);
    }
    $theme_mods = get_theme_mods();

    $cache_value_before = wp_cache_get('scan_data', 'goetz-migration', false, $cache_found_before);
    $cache_snapshot_taken = true;
    wp_cache_delete('scan_data', 'goetz-migration');

    $before_plan = goetz_migration_safety_persistent_fingerprint();
    $discovered = $scraper->scan_site('https://goetzlegal.com');
    goetz_migration_safety_assert($discovered === $fixtures, 'Legacy scan alias changed fixture discovery.');
    $plan = $scraper->plan_pages($discovered, false);
    $after_plan = goetz_migration_safety_persistent_fingerprint();

    goetz_migration_safety_assert(
        $after_plan === $before_plan,
        'Dry-run discovery/planning changed persistent post, media, menu, option, transient, or metadata state.'
    );
    $cache_found_after = null;
    wp_cache_get('scan_data', 'goetz-migration', false, $cache_found_after);
    goetz_migration_safety_assert(! $cache_found_after, 'Dry-run discovery stored scan results in object cache.');
    goetz_migration_safety_assert(
        ($plan['summary'] ?? []) === [
            'planned_create'   => 1,
            'planned_update'   => 0,
            'skipped_existing' => 1,
        ],
        'Default plan summary is not create-only: ' . wp_json_encode($plan['summary'] ?? null)
    );

    $existing_item = goetz_migration_safety_plan_item($plan, $existing_slug);
    $missing_item = goetz_migration_safety_plan_item($plan, $missing_slug);
    goetz_migration_safety_assert(
        ($existing_item['status'] ?? '') === 'skipped_existing'
            && ($existing_item['action'] ?? '') === 'skip'
            && preg_match('/^[a-f0-9]{64}$/', (string) ($existing_item['review_fingerprint'] ?? '')) === 1,
        'Default planning did not skip the existing page.'
    );
    goetz_migration_safety_assert(
        ! array_key_exists('content', $existing_item) && ($existing_item['diff'] ?? '') === '',
        'Existing page content was built even though force mode was not requested.'
    );
    goetz_migration_safety_assert(
        ($missing_item['status'] ?? '') === 'create'
            && ($missing_item['action'] ?? '') === 'create'
            && ($missing_item['content'] ?? '') === $missing_content
            && preg_match('/^[a-f0-9]{64}$/', (string) ($missing_item['review_fingerprint'] ?? '')) === 1,
        'Missing page was not planned for create-only import.'
    );
    goetz_migration_safety_assert(
        str_contains((string) ($missing_item['diff'] ?? ''), 'core/paragraph')
            && str_contains((string) ($missing_item['diff'] ?? ''), 'Missing page source copy.')
            && ! str_contains((string) ($missing_item['diff'] ?? ''), '<!-- wp:'),
        'Dry-run does not expose a normalized, human-readable block diff.'
    );
    goetz_migration_safety_assert(
        $scraper->built_slugs === [$missing_slug],
        'Default planner did not skip the existing page before content construction.'
    );

    $options_before_apply = [];
    foreach ($site_options as $option_name => $_snapshot) {
        $options_before_apply[$option_name] = goetz_migration_safety_option_snapshot($option_name);
    }
    $apply = $scraper->apply_plan($plan);
    goetz_migration_safety_assert(
        ($apply['summary'] ?? []) === ['created' => 1, 'updated' => 0, 'skipped' => 1, 'errors' => 0],
        'Default apply was not create-only: ' . wp_json_encode($apply['summary'] ?? null)
    );

    $missing_page = get_page_by_path($missing_slug, OBJECT, 'page');
    goetz_migration_safety_assert($missing_page instanceof WP_Post, 'Default import did not create the missing page.');
    $created_ids[] = (int) $missing_page->ID;
    goetz_migration_safety_assert(
        $missing_page->post_content === $missing_content,
        'Created page content did not match the reviewed plan.'
    );
    goetz_migration_safety_assert(
        get_post_meta((int) $missing_page->ID, '_yoast_wpseo_title', true) === ''
            && get_post_meta((int) $missing_page->ID, '_yoast_wpseo_metadesc', true) === ''
            && get_post_meta((int) $missing_page->ID, '_yoast_wpseo_canonical', true) === '',
        'Legacy import wrote Yoast-managed title, description, or environment-specific canonical metadata.'
    );
    goetz_migration_safety_assert(
        get_post_meta((int) $missing_page->ID, '_goetz_source_url', true) === $fixtures[1]['url']
            && get_post_meta((int) $missing_page->ID, '_goetz_source_hash', true) === $fixtures[1]['source_hash']
            && get_post_meta((int) $missing_page->ID, '_goetz_content_version', true) === GOETZ_MIGRATION_CONTENT_VERSION,
        'Created page did not retain importer-owned provenance metadata.'
    );
    goetz_migration_safety_assert(
        $scraper->localized_slugs === [$missing_slug],
        'Media localization ran for an existing page during create-only apply.'
    );
    goetz_migration_safety_assert(
        goetz_migration_safety_post_snapshot($existing_id) === $existing_snapshot,
        'Default import changed existing editor content, title, template, or Yoast metadata.'
    );
    $menu_after_apply = get_post($menu_item_id);
    goetz_migration_safety_assert(
        $menu_after_apply instanceof WP_Post
            && $menu_snapshot instanceof WP_Post
            && $menu_after_apply->post_title === $menu_snapshot->post_title
            && (int) get_post_meta($menu_item_id, '_menu_item_object_id', true) === $existing_id,
        'Default import changed the existing menu assignment or editor menu label.'
    );
    foreach ($options_before_apply as $option_name => $snapshot) {
        goetz_migration_safety_assert(
            goetz_migration_safety_option_snapshot($option_name) === $snapshot,
            "Default import changed site option: {$option_name}"
        );
    }
    goetz_migration_safety_assert(get_theme_mods() === $theme_mods, 'Default import changed logo or menu theme mods.');

    $repeat = $scraper->apply_plan($plan);
    goetz_migration_safety_assert(
        ($repeat['summary'] ?? []) === ['created' => 0, 'updated' => 0, 'skipped' => 2, 'errors' => 0],
        'A stale create plan did not fail safely when the page already existed.'
    );

    $force_plan = $scraper->plan_pages([$fixtures[0]], true);
    $force_existing = goetz_migration_safety_plan_item($force_plan, $existing_slug);
    goetz_migration_safety_assert(
        ($force_existing['status'] ?? '') === 'update_existing'
            && ($force_existing['action'] ?? '') === 'update'
            && ($force_existing['content_hash_before'] ?? '') === hash('sha256', $existing_content)
            && ($force_existing['state_fingerprint_before'] ?? '') !== ''
            && preg_match('/^[a-f0-9]{64}$/', (string) ($force_existing['review_fingerprint'] ?? '')) === 1,
        'Explicit force planning did not bind the update to the reviewed existing content.'
    );
    $force_diff = (string) ($force_existing['diff'] ?? '');
    goetz_migration_safety_assert(
        str_contains($force_diff, '--- current/' . $existing_slug)
            && str_contains($force_diff, '+++ import/' . $existing_slug)
            && str_contains($force_diff, 'Editor-authored content must survive.')
            && str_contains($force_diff, 'Imported replacement')
            && str_contains($force_diff, 'core/paragraph')
            && str_contains($force_diff, 'core/group')
            && ! str_contains($force_diff, '<!-- wp:'),
        'Force plan is missing the normalized human-readable block diff.'
    );
    $force_plan_again = $scraper->plan_pages([$fixtures[0]], true);
    $force_existing_again = goetz_migration_safety_plan_item($force_plan_again, $existing_slug);
    goetz_migration_safety_assert(
        $force_existing_again['diff'] === $force_diff
            && $force_existing_again['review_fingerprint'] === $force_existing['review_fingerprint'],
        'Normalized block diff or its review fingerprint is not deterministic.'
    );
    goetz_migration_safety_assert(
        goetz_migration_safety_post_snapshot($existing_id) === $existing_snapshot,
        'Force planning changed the existing page before approval.'
    );

    $tampered_force_plan = $force_plan;
    $tampered_force_plan['items'][0]['content'] = $replacement_content . '<!-- tampered after review -->';
    $tampered_force_plan['items'][0]['content_hash_after'] = hash(
        'sha256',
        $tampered_force_plan['items'][0]['content']
    );
    $tampered_force_baseline = goetz_migration_safety_persistent_fingerprint();
    $tampered_force = $scraper->apply_plan($tampered_force_plan);
    goetz_migration_safety_assert(
        ($tampered_force['summary'] ?? []) === ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1]
            && (($tampered_force['items'][0]['status'] ?? '') === 'invalid_plan'),
        'A force plan accepted content that no longer matched the reviewed diff.'
    );
    goetz_migration_safety_assert(
        goetz_migration_safety_persistent_fingerprint() === $tampered_force_baseline,
        'Rejected tampered force plan changed persistent state.'
    );

    $tampered_diff_plan = $force_plan;
    $tampered_diff_plan['items'][0]['diff'] .= "\n+ unreviewed diff line";
    $tampered_diff_baseline = goetz_migration_safety_persistent_fingerprint();
    $tampered_diff = $scraper->apply_plan($tampered_diff_plan);
    goetz_migration_safety_assert(
        ($tampered_diff['summary'] ?? []) === ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1]
            && (($tampered_diff['items'][0]['status'] ?? '') === 'invalid_plan')
            && goetz_migration_safety_persistent_fingerprint() === $tampered_diff_baseline,
        'A force plan accepted a displayed diff that changed after review.'
    );

    $tampered_action_plan = $force_plan;
    $tampered_action_plan['items'][0]['action'] = 'create';
    $tampered_action_baseline = goetz_migration_safety_persistent_fingerprint();
    $tampered_action = $scraper->apply_plan($tampered_action_plan);
    goetz_migration_safety_assert(
        ($tampered_action['summary'] ?? []) === ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1]
            && (($tampered_action['items'][0]['status'] ?? '') === 'invalid_plan')
            && goetz_migration_safety_persistent_fingerprint() === $tampered_action_baseline,
        'A force plan accepted an action that changed after review.'
    );

    $tampered_source_plan = $force_plan;
    $tampered_source_plan['items'][0]['source_hash'] = 'unreviewed-source-provenance';
    $tampered_source_baseline = goetz_migration_safety_persistent_fingerprint();
    $tampered_source = $scraper->apply_plan($tampered_source_plan);
    goetz_migration_safety_assert(
        ($tampered_source['summary'] ?? []) === ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1]
            && (($tampered_source['items'][0]['status'] ?? '') === 'invalid_plan')
            && goetz_migration_safety_persistent_fingerprint() === $tampered_source_baseline,
        'A force plan accepted source provenance that changed after review.'
    );

    wp_update_post(['ID' => $existing_id, 'post_title' => 'Editor changed title after review']);
    $stale_force_baseline = goetz_migration_safety_persistent_fingerprint();
    $stale_force = $scraper->apply_plan($force_plan);
    goetz_migration_safety_assert(
        ($stale_force['summary'] ?? []) === ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1]
            && (($stale_force['items'][0]['status'] ?? '') === 'conflict'),
        'A force plan did not fail closed after editor state drifted.'
    );
    goetz_migration_safety_assert(
        goetz_migration_safety_persistent_fingerprint() === $stale_force_baseline,
        'Rejected stale force plan changed persistent state.'
    );

    wp_update_post(['ID' => $existing_id, 'post_title' => 'Editor Preserved Title']);
    $concurrent_force_plan = $scraper->plan_pages([$fixtures[0]], true);
    $concurrent_media_offset = count($scraper->owned_attachment_ids);
    $scraper->mutate_during_localize_slug = $existing_slug;
    $scraper->create_owned_media_slug = $existing_slug;
    $concurrent_force = $scraper->apply_plan($concurrent_force_plan);
    goetz_migration_safety_assert(
        ($concurrent_force['summary'] ?? []) === ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1]
            && (($concurrent_force['items'][0]['status'] ?? '') === 'conflict'),
        'Force apply did not detect an editor save made during media preparation.'
    );
    goetz_migration_safety_assert(
        (string) get_post_field('post_content', $existing_id) === $scraper->concurrent_editor_content,
        'Force apply overwrote an editor save made during media preparation.'
    );
    $concurrent_owned_ids = array_slice($scraper->owned_attachment_ids, $concurrent_media_offset);
    $concurrent_owned_files = array_slice($scraper->owned_attachment_files, $concurrent_media_offset);
    goetz_migration_safety_assert($concurrent_owned_ids !== [], 'Race fixture did not create owned media.');
    foreach ($concurrent_owned_ids as $owned_attachment_id) {
        goetz_migration_safety_assert(
            get_post($owned_attachment_id) === null,
            'Force conflict retained an attachment created by the rejected apply.'
        );
    }
    foreach ($concurrent_owned_files as $owned_attachment_file) {
        goetz_migration_safety_assert(
            ! file_exists($owned_attachment_file),
            'Force conflict retained a file created by the rejected apply.'
        );
    }

    wp_update_post(['ID' => $existing_id, 'post_content' => $existing_content]);
    $upload = wp_upload_dir();
    goetz_migration_safety_assert(empty($upload['error']), 'Could not resolve the reusable media directory.');
    goetz_migration_safety_assert(wp_mkdir_p((string) $upload['path']), 'Could not create the reusable media directory.');
    $reused_attachment_file = trailingslashit((string) $upload['path'])
        . 'goetz-import-reused-' . wp_generate_uuid4() . '.jpg';
    goetz_migration_safety_assert(
        file_put_contents($reused_attachment_file, 'Goetz reusable attachment fixture') !== false,
        'Could not write the reusable media fixture.'
    );
    $seeded_reused_attachment_id = wp_insert_attachment([
        'post_mime_type' => 'image/jpeg',
        'post_status'    => 'inherit',
        'post_title'     => 'Goetz reusable attachment fixture',
    ], $reused_attachment_file, $existing_id, true);
    goetz_migration_safety_assert(
        ! is_wp_error($seeded_reused_attachment_id),
        'Could not seed the reusable attachment fixture.'
    );
    $reused_attachment_id = (int) $seeded_reused_attachment_id;
    update_attached_file($reused_attachment_id, $reused_attachment_file);
    $reused_media_url = 'https://goetzlegal.com/wp-content/uploads/reused-safety-fixture.jpg';
    update_post_meta($reused_attachment_id, '_goetz_source_media_url', $reused_media_url);

    $reused_fixture = $fixtures[0];
    $reused_fixture['fixture_content'] = $replacement_content
        . '<!-- wp:image --><figure class="wp-block-image"><img src="'
        . $reused_media_url
        . '" alt="Reusable fixture"></figure><!-- /wp:image -->';
    $reused_force_plan = $scraper->plan_pages([$reused_fixture], true);
    $scraper->use_parent_localizer_slug = $existing_slug;
    $scraper->mutate_during_localize_slug = $existing_slug;
    $reused_conflict = $scraper->apply_plan($reused_force_plan);
    goetz_migration_safety_assert(
        ($reused_conflict['summary'] ?? []) === ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1]
            && (($reused_conflict['items'][0]['status'] ?? '') === 'conflict')
            && get_post($reused_attachment_id) instanceof WP_Post
            && file_exists($reused_attachment_file),
        'Force rollback deleted a preexisting attachment that localization only reused.'
    );

    wp_update_post(['ID' => $existing_id, 'post_content' => $existing_content]);
    $failed_force_plan = $scraper->plan_pages([$fixtures[0]], true);
    $failed_force_baseline = goetz_migration_safety_persistent_fingerprint();
    $failed_force_media_offset = count($scraper->owned_attachment_ids);
    $scraper->create_owned_media_slug = $existing_slug;
    $scraper->fail_update_slug = $existing_slug;
    $failed_force = $scraper->apply_plan($failed_force_plan);
    goetz_migration_safety_assert(
        ($failed_force['summary'] ?? []) === ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1]
            && (($failed_force['items'][0]['status'] ?? '') === 'error')
            && goetz_migration_safety_persistent_fingerprint() === $failed_force_baseline,
        'Forced update failure retained importer-owned persistent side effects.'
    );
    foreach (array_slice($scraper->owned_attachment_ids, $failed_force_media_offset) as $owned_attachment_id) {
        goetz_migration_safety_assert(get_post($owned_attachment_id) === null, 'Forced update failure retained owned media.');
    }
    foreach (array_slice($scraper->owned_attachment_files, $failed_force_media_offset) as $owned_attachment_file) {
        goetz_migration_safety_assert(! file_exists($owned_attachment_file), 'Forced update failure retained an owned file.');
    }

    $failure_fixture = [
        'title'           => 'Create Failure Safety Page',
        'slug'            => $failure_slug,
        'path'            => '/' . $failure_slug . '/',
        'url'             => 'https://goetzlegal.com/' . $failure_slug . '/',
        'raw_content'     => '<p>Create failure source.</p>',
        'fixture_content' => '<!-- wp:paragraph --><p>Create failure source.</p><!-- /wp:paragraph -->',
        'method'          => 'fixture',
        'modified'        => '2026-07-17T00:00:00',
        'yoast'           => [],
        'media_count'     => 1,
        'source_hash'     => hash('sha256', 'create-failure'),
    ];
    $create_failure_plan = $scraper->plan_pages([$failure_fixture], false);
    $create_failure_baseline = goetz_migration_safety_persistent_fingerprint();
    $create_failure_media_offset = count($scraper->owned_attachment_ids);
    $scraper->create_owned_media_slug = $failure_slug;
    $scraper->fail_update_slug = $failure_slug;
    $create_failure = $scraper->apply_plan($create_failure_plan);
    goetz_migration_safety_assert(
        ($create_failure['summary'] ?? []) === ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1]
            && (($create_failure['items'][0]['status'] ?? '') === 'error')
            && get_page_by_path($failure_slug, OBJECT, 'page') === null
            && goetz_migration_safety_persistent_fingerprint() === $create_failure_baseline,
        'Create failure retained its page or importer-owned persistent side effects.'
    );
    foreach (array_slice($scraper->owned_attachment_ids, $create_failure_media_offset) as $owned_attachment_id) {
        goetz_migration_safety_assert(get_post($owned_attachment_id) === null, 'Create failure retained owned media.');
    }
    foreach (array_slice($scraper->owned_attachment_files, $create_failure_media_offset) as $owned_attachment_file) {
        goetz_migration_safety_assert(! file_exists($owned_attachment_file), 'Create failure retained an owned file.');
    }

    wp_update_post(['ID' => $existing_id, 'post_content' => $existing_content]);
    $fresh_force_plan = $scraper->plan_pages([$fixtures[0]], true);
    $meta_before_force = get_post_meta($existing_id);
    $fresh_force = $scraper->apply_plan($fresh_force_plan);
    goetz_migration_safety_assert(
        ($fresh_force['summary'] ?? []) === ['created' => 0, 'updated' => 1, 'skipped' => 0, 'errors' => 0],
        'A freshly reviewed force plan did not update exactly one page.'
    );
    $forced_post = get_post($existing_id);
    goetz_migration_safety_assert(
        $forced_post instanceof WP_Post
            && $forced_post->post_content === $replacement_content
            && $forced_post->post_title === 'Editor Preserved Title'
            && $forced_post->post_status === 'draft',
        'Force apply changed fields outside the reviewed page content.'
    );
    goetz_migration_safety_assert(
        get_post_meta($existing_id, '_wp_page_template', true) === 'page-templates/template-contact.php'
            && get_post_meta($existing_id) === $meta_before_force,
        'Force apply changed the page template or existing metadata.'
    );
    $menu_after_force = get_post($menu_item_id);
    goetz_migration_safety_assert(
        $menu_after_force instanceof WP_Post
            && $menu_after_force->post_title === 'Editor Menu Label'
            && (int) get_post_meta($menu_item_id, '_menu_item_object_id', true) === $existing_id,
        'Force apply changed the existing menu assignment.'
    );

    wp_update_post(['ID' => $existing_id, 'post_content' => $existing_content]);

    $cli_reflection = new ReflectionClass(Goetz_Migration_CLI::class);
    $force_policy = $cli_reflection->getMethod('force_confirmation_required');
    $force_policy->setAccessible(true);
    goetz_migration_safety_assert(
        $force_policy->invoke(null, true, false)
            && ! $force_policy->invoke(null, false, false)
            && ! $force_policy->invoke(null, true, true),
        'CLI force mode can bypass the explicit confirmation/--yes contract.'
    );
    $error_policy = $cli_reflection->getMethod('import_has_errors');
    $error_policy->setAccessible(true);
    goetz_migration_safety_assert(
        $error_policy->invoke(null, ['errors' => 1])
            && ! $error_policy->invoke(null, ['errors' => 0])
            && ! $error_policy->invoke(null, []),
        'CLI apply errors do not reliably select the nonzero-exit path.'
    );
    $public_cli_methods = [];
    foreach ($cli_reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $public_method) {
        if ($public_method->getDeclaringClass()->getName() === Goetz_Migration_CLI::class) {
            $public_cli_methods[] = $public_method->getName();
        }
    }
    sort($public_cli_methods);
    goetz_migration_safety_assert(
        $public_cli_methods === ['import', 'scan'],
        'WP-CLI exposes an unintended public migration subcommand: ' . wp_json_encode($public_cli_methods)
    );
    $command_args = ['goetz-migration'];
    $registered_command = WP_CLI::get_root_command()->find_subcommand($command_args);
    goetz_migration_safety_assert(
        is_object($registered_command)
            && method_exists($registered_command, 'get_name')
            && $registered_command->get_name() === 'goetz-migration'
            && $command_args === [],
        'The guarded goetz-migration CLI command is not registered.'
    );

    $admin_users = get_users([
        'role'   => 'administrator',
        'number' => 1,
        'fields' => 'ids',
    ]);
    goetz_migration_safety_assert(! empty($admin_users[0]), 'No administrator is available for admin-handler safety checks.');

    $admin_fixtures = [$fixtures[0], [
        'title'           => 'Admin Missing Safety Page',
        'slug'            => $admin_missing_slug,
        'path'            => '/' . $admin_missing_slug . '/',
        'url'             => 'https://goetzlegal.com/' . $admin_missing_slug . '/',
        'raw_content'     => '<p>Admin source.</p>',
        'fixture_content' => '<!-- wp:paragraph --><p>Admin create-only page.</p><!-- /wp:paragraph -->',
        'method'          => 'fixture',
        'modified'        => '2026-07-17T00:00:00',
        'yoast'           => [],
        'media_count'     => 0,
        'source_hash'     => hash('sha256', 'admin-missing'),
    ]];
    $admin_scraper = new Goetz_Migration_Safety_Scraper($admin_fixtures);
    $admin_baseline = goetz_migration_safety_persistent_fingerprint();

    wp_set_current_user(0);
    $forbidden = goetz_migration_handle_admin_request([
        'action'     => 'preview',
        'source_url' => 'https://goetzlegal.com',
        'nonce'      => 'invalid',
    ], $admin_scraper);
    goetz_migration_safety_assert(
        is_wp_error($forbidden) && $forbidden->get_error_code() === 'goetz_migration_forbidden',
        'Admin handler did not reject a user without manage_options.'
    );
    goetz_migration_safety_assert(
        goetz_migration_safety_persistent_fingerprint() === $admin_baseline,
        'Rejected capability check changed persistent state.'
    );

    wp_set_current_user((int) $admin_users[0]);
    $bad_nonce = goetz_migration_handle_admin_request([
        'action'     => 'preview',
        'source_url' => 'https://goetzlegal.com',
        'nonce'      => 'invalid',
    ], $admin_scraper);
    goetz_migration_safety_assert(
        is_wp_error($bad_nonce) && $bad_nonce->get_error_code() === 'goetz_migration_bad_nonce',
        'Admin handler accepted a bad nonce.'
    );
    goetz_migration_safety_assert(
        goetz_migration_safety_persistent_fingerprint() === $admin_baseline,
        'Rejected nonce check changed persistent state.'
    );

    $valid_nonce = wp_create_nonce('goetz_migration_nonce');
    $admin_force = goetz_migration_handle_admin_request([
        'action'         => 'import',
        'source_url'     => 'https://goetzlegal.com',
        'nonce'          => $valid_nonce,
        'force_existing' => '1',
    ], $admin_scraper);
    goetz_migration_safety_assert(
        is_wp_error($admin_force) && $admin_force->get_error_code() === 'goetz_migration_force_unavailable',
        'An ordinary admin request exposed force-existing mode.'
    );
    goetz_migration_safety_assert(
        goetz_migration_safety_persistent_fingerprint() === $admin_baseline
            && (string) get_post_field('post_content', $existing_id) === $existing_content,
        'Rejected admin force request changed existing editor content.'
    );

    $admin_force_hyphen = goetz_migration_handle_admin_request([
        'action'         => 'import',
        'source_url'     => 'https://goetzlegal.com',
        'nonce'          => $valid_nonce,
        'force-existing' => '1',
    ], $admin_scraper);
    goetz_migration_safety_assert(
        is_wp_error($admin_force_hyphen)
            && $admin_force_hyphen->get_error_code() === 'goetz_migration_force_unavailable'
            && goetz_migration_safety_persistent_fingerprint() === $admin_baseline,
        'A hyphenated admin force request bypassed create-only behavior.'
    );

    $unknown_action = goetz_migration_handle_admin_request([
        'action'     => 'overwrite',
        'source_url' => 'https://goetzlegal.com',
        'nonce'      => $valid_nonce,
    ], $admin_scraper);
    goetz_migration_safety_assert(
        is_wp_error($unknown_action)
            && $unknown_action->get_error_code() === 'goetz_migration_unknown_action'
            && goetz_migration_safety_persistent_fingerprint() === $admin_baseline,
        'Unknown admin action was not rejected without writes.'
    );

    $original_post_request = $_POST;
    $_POST = [];
    if (! function_exists('submit_button')) {
        require_once ABSPATH . 'wp-admin/includes/template.php';
    }
    ob_start();
    try {
        goetz_migration_admin_page();
        $admin_html = (string) ob_get_contents();
    } finally {
        ob_end_clean();
        $_POST = $original_post_request;
    }
    goetz_migration_safety_assert(
        str_contains($admin_html, 'Create Missing Pages')
            && ! str_contains($admin_html, 'force-existing')
            && ! str_contains($admin_html, 'force_existing')
            && ! str_contains(strtolower($admin_html), 'create/update')
            && ! str_contains(strtolower($admin_html), 'create or update'),
        'Rendered admin screen exposes force controls or destructive create/update wording.'
    );

    $admin_preview = goetz_migration_handle_admin_request([
        'action'     => 'preview',
        'source_url' => 'https://goetzlegal.com',
        'nonce'      => $valid_nonce,
    ], $admin_scraper);
    goetz_migration_safety_assert(
        is_array($admin_preview)
            && ($admin_preview['mode'] ?? '') === 'dry-run'
            && (goetz_migration_safety_plan_item($admin_preview['plan'] ?? [], $existing_slug)['status'] ?? '') === 'skipped_existing'
            && (goetz_migration_safety_plan_item($admin_preview['plan'] ?? [], $admin_missing_slug)['status'] ?? '') === 'create',
        'Valid admin preview did not use the create-only planner.'
    );
    goetz_migration_safety_assert(
        goetz_migration_safety_persistent_fingerprint() === $admin_baseline,
        'Valid admin dry-run wrote posts, media, menus, options, transients, cache-backed options, or metadata.'
    );

    $admin_apply = goetz_migration_handle_admin_request([
        'action'     => 'import',
        'source_url' => 'https://goetzlegal.com',
        'nonce'      => $valid_nonce,
    ], $admin_scraper);
    goetz_migration_safety_assert(
        is_array($admin_apply)
            && ($admin_apply['mode'] ?? '') === 'apply'
            && (($admin_apply['result']['summary']['created'] ?? 0) === 1)
            && (($admin_apply['result']['summary']['updated'] ?? -1) === 0),
        'Valid admin create-missing request was not create-only.'
    );
    $admin_created = get_page_by_path($admin_missing_slug, OBJECT, 'page');
    goetz_migration_safety_assert($admin_created instanceof WP_Post, 'Admin create-missing request did not create its page.');
    $created_ids[] = (int) $admin_created->ID;
    goetz_migration_safety_assert(
        (string) get_post_field('post_content', $existing_id) === $existing_content,
        'Admin create-missing request overwrote an existing page.'
    );
} finally {
    wp_set_current_user($initial_user_id);

    foreach ([$missing_slug, $admin_missing_slug, $failure_slug] as $fixture_slug) {
        $fixture_page = get_page_by_path($fixture_slug, OBJECT, 'page');
        if ($fixture_page instanceof WP_Post) {
            $created_ids[] = (int) $fixture_page->ID;
        }
    }
    foreach (array_unique($created_ids) as $created_id) {
        if (get_post($created_id)) {
            wp_delete_post($created_id, true);
        }
    }

    if ($menu_item_id > 0 && get_post($menu_item_id)) {
        wp_delete_post($menu_item_id, true);
    }
    if ($menu_id > 0 && wp_get_nav_menu_object($menu_id)) {
        wp_delete_nav_menu($menu_id);
    }
    if ($existing_id > 0 && get_post($existing_id)) {
        wp_delete_post($existing_id, true);
    }
    if ($reused_attachment_id && get_post($reused_attachment_id)) {
        wp_delete_attachment($reused_attachment_id, true);
    }
    if ($reused_attachment_file !== '' && file_exists($reused_attachment_file)) {
        wp_delete_file($reused_attachment_file);
    }
    foreach ($scraper->owned_attachment_ids as $owned_attachment_id) {
        if (get_post($owned_attachment_id)) {
            wp_delete_attachment($owned_attachment_id, true);
        }
    }
    foreach ($scraper->owned_attachment_files as $owned_attachment_file) {
        if (file_exists($owned_attachment_file)) {
            wp_delete_file($owned_attachment_file);
        }
    }

    if ($cache_snapshot_taken) {
        if ($cache_found_before) {
            wp_cache_set('scan_data', $cache_value_before, 'goetz-migration');
        } else {
            wp_cache_delete('scan_data', 'goetz-migration');
        }
        $restored_cache_found = false;
        $restored_cache_value = wp_cache_get('scan_data', 'goetz-migration', false, $restored_cache_found);
        $cache_restore_verified = $restored_cache_found === $cache_found_before
            && (! $cache_found_before || $restored_cache_value === $cache_value_before);
    }

    if ($original_cache_snapshot_taken) {
        if ($original_cache_found) {
            wp_cache_set('scan_data', $original_cache_value, 'goetz-migration');
        } else {
            wp_cache_delete('scan_data', 'goetz-migration');
        }
    }
}

goetz_migration_safety_assert($cache_restore_verified, 'Harness cleanup did not restore preexisting object-cache state.');
WP_CLI::success('Legacy importer create-only safety checks passed.');
