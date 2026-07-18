<?php

if (! defined('ABSPATH')) {
    fwrite(STDERR, "homepage-migration.php must run through WP-CLI.\n");
    exit(1);
}

$goetz_test_environment_override = getenv('WP_ENVIRONMENT_TYPE');
$goetz_test_environment = is_string($goetz_test_environment_override) && $goetz_test_environment_override !== ''
    ? strtolower($goetz_test_environment_override)
    : (function_exists('wp_get_environment_type') ? wp_get_environment_type() : '');
$goetz_test_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
$goetz_test_host = is_string($goetz_test_host) ? strtolower(trim($goetz_test_host, '[]')) : '';
$goetz_is_loopback = $goetz_test_host === 'localhost'
    || $goetz_test_host === '::1'
    || preg_match('/^127(?:\.\d{1,3}){3}$/', $goetz_test_host) === 1;
if (! $goetz_is_loopback
    || ! in_array($goetz_test_environment, ['local', 'development', 'test'], true)
    || getenv('GOETZ_ALLOW_MUTATING_TESTS') !== '1') {
    fwrite(
        STDERR,
        "Refusing mutating homepage integration checks outside an explicit loopback local/test environment.\n"
    );
    exit(1);
}

/**
 * @param bool $condition
 */
function goetz_homepage_migration_assert($condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

foreach ([
    'Goetz\\Site\\Migrations\\Media_Seeder',
    'Goetz\\Site\\Migrations\\Homepage_Migration',
    'Goetz\\Site\\Migrations\\Site_Bootstrap',
    'Goetz\\Site\\Editor\\Homepage_Editor',
    'Goetz\\Site\\CLI\\Migrate_Command',
] as $required_class) {
    goetz_homepage_migration_assert(
        class_exists($required_class),
        "Required homepage migration class is missing: {$required_class}"
    );
}

$config_path = GOETZ_SITE_PATH . 'config/homepage.php';
goetz_homepage_migration_assert(is_readable($config_path), 'The tracked homepage migration configuration is missing.');

/**
 * @return array{exists: bool, value: mixed}
 */
function goetz_homepage_snapshot_option(string $name): array
{
    $sentinel = '__goetz_homepage_missing_' . wp_generate_uuid4();
    $value = get_option($name, $sentinel);

    return [
        'exists' => $value !== $sentinel,
        'value'  => $value !== $sentinel ? $value : null,
    ];
}

/**
 * @param array{exists: bool, value: mixed} $snapshot
 */
function goetz_homepage_restore_option(string $name, array $snapshot): void
{
    if ($snapshot['exists']) {
        update_option($name, $snapshot['value']);
        return;
    }

    delete_option($name);
}

/**
 * @return array<int, int>
 */
function goetz_homepage_attachment_ids(): array
{
    return array_map('intval', get_posts([
        'post_type'              => 'attachment',
        'post_status'            => 'inherit',
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

/**
 * @param array<int, int> $created_ids
 * @param array<int, array<int, mixed>> $initial_seed_meta
 * @param array<int, array<int, mixed>> $initial_checksum_meta
 * @param array<int, int> $touched_existing_ids
 */
function goetz_homepage_restore_attachments(
    array $created_ids,
    array $initial_seed_meta,
    array $initial_checksum_meta,
    array $touched_existing_ids
): void
{
    foreach (array_unique(array_map('intval', $created_ids)) as $attachment_id) {
        if ($attachment_id > 0 && get_post_type($attachment_id) === 'attachment') {
            wp_delete_attachment($attachment_id, true);
        }
    }

    foreach (array_unique(array_map('intval', $touched_existing_ids)) as $attachment_id) {
        if ($attachment_id < 1 || ! array_key_exists($attachment_id, $initial_seed_meta)) {
            continue;
        }
        delete_post_meta($attachment_id, Goetz\Site\Migrations\Media_Seeder::META_KEY);
        delete_post_meta($attachment_id, Goetz\Site\Migrations\Media_Seeder::CHECKSUM_META_KEY);
        foreach ($initial_seed_meta[$attachment_id] ?? [] as $meta_value) {
            add_post_meta(
                $attachment_id,
                Goetz\Site\Migrations\Media_Seeder::META_KEY,
                $meta_value
            );
        }
        foreach ($initial_checksum_meta[$attachment_id] ?? [] as $meta_value) {
            add_post_meta(
                $attachment_id,
                Goetz\Site\Migrations\Media_Seeder::CHECKSUM_META_KEY,
                $meta_value
            );
        }
    }
}

/**
 * @return array<int, int>
 */
function goetz_homepage_menu_ids(): array
{
    return array_map(
        static fn(WP_Term $menu): int => (int) $menu->term_id,
        wp_get_nav_menus(['orderby' => 'term_id', 'order' => 'ASC'])
    );
}

/**
 * @param array<int, int> $owned_ids
 */
function goetz_homepage_remove_owned_menus(array $owned_ids): void
{
    foreach (array_unique(array_map('intval', $owned_ids)) as $menu_id) {
        if ($menu_id > 0 && wp_get_nav_menu_object($menu_id) instanceof WP_Term) {
            wp_delete_nav_menu($menu_id);
        }
    }
}

/**
 * @param array<int, array{label: string, slug: string}> $expected
 */
function goetz_homepage_assert_native_menu(int $menu_id, array $expected, int $home_id): void
{
    $items = wp_get_nav_menu_items($menu_id, [
        'orderby'     => 'menu_order',
        'order'       => 'ASC',
        'post_status' => 'publish',
    ]);
    goetz_homepage_migration_assert(is_array($items) && count($items) === count($expected), 'Native menu item count changed.');
    foreach ($expected as $index => $definition) {
        $item = $items[$index] ?? null;
        $page = $definition['slug'] === 'home'
            ? get_post($home_id)
            : get_page_by_path($definition['slug'], OBJECT, 'page');
        goetz_homepage_migration_assert(
            $item instanceof WP_Post
                && $page instanceof WP_Post
                && $item->title === $definition['label']
                && $item->type === 'post_type'
                && $item->object === 'page'
                && (int) $item->object_id === (int) $page->ID
                && (int) $item->menu_order === $index + 1,
            "Native menu entry changed at index {$index}."
        );
    }
}

/**
 * @param callable(): mixed $callback
 */
function goetz_homepage_expect_runtime_exception(callable $callback, string $message_fragment): void
{
    $caught = null;
    try {
        $callback();
    } catch (RuntimeException $exception) {
        $caught = $exception;
    }

    goetz_homepage_migration_assert(
        $caught instanceof RuntimeException,
        "Expected RuntimeException containing: {$message_fragment}"
    );
    goetz_homepage_migration_assert(
        str_contains($caught->getMessage(), $message_fragment),
        "Unexpected RuntimeException: {$caught->getMessage()}"
    );
}

/**
 * @param array<string, mixed> $block
 */
function goetz_homepage_assert_named_tree(array $block): void
{
    goetz_homepage_migration_assert(
        isset($block['blockName']) && is_string($block['blockName']) && $block['blockName'] !== '',
        'The canonical homepage tree contains a freeform or invalid block.'
    );

    foreach ($block['innerBlocks'] ?? [] as $child) {
        goetz_homepage_migration_assert(is_array($child), 'A canonical child block is malformed.');
        goetz_homepage_assert_named_tree($child);
    }
}

/**
 * @param array<string, mixed> $attrs
 */
function goetz_homepage_assert_portable_image(
    array $attrs,
    string $id_key,
    string $url_key,
    string $label
): void {
    $attachment_id = absint($attrs[$id_key] ?? 0);
    $url = isset($attrs[$url_key]) && is_scalar($attrs[$url_key])
        ? (string) $attrs[$url_key]
        : '';

    goetz_homepage_migration_assert($attachment_id > 0, "{$label} is missing its attachment ID.");
    goetz_homepage_migration_assert(
        wp_attachment_is_image($attachment_id),
        "{$label} does not reference an image attachment."
    );
    goetz_homepage_migration_assert(
        str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_contains($url, '://'),
        "{$label} URL is not root-relative and portable: {$url}"
    );
}

/**
 * @return array<string, array{size: int, sha256: string}>
 */
function goetz_homepage_upload_fingerprint(): array
{
    $uploads = wp_get_upload_dir();
    $root = isset($uploads['basedir']) && is_string($uploads['basedir'])
        ? $uploads['basedir']
        : '';
    if ($root === '' || ! is_dir($root)) {
        return [];
    }

    $fingerprint = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->isLink()) {
            continue;
        }
        $path = $file->getPathname();
        $relative = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
        $hash = hash_file('sha256', $path);
        $fingerprint[$relative] = [
            'size'   => $file->getSize(),
            'sha256' => is_string($hash) ? $hash : '',
        ];
    }
    ksort($fingerprint, SORT_STRING);

    return $fingerprint;
}

/**
 * Return the exact create-only legacy shape with same-origin URLs. The year
 * folders are test fixtures; production matching validates only same-origin
 * upload paths and exact configured filenames.
 *
 * @param array<string, mixed> $config
 */
function goetz_homepage_known_legacy_content(array $config): string
{
    $homepage = $config['homepage'];
    $asset_url = static function (string $key, string $year_month = '2022/08') use ($config): string {
        $filename = (string) $config['assets'][$key]['filename'];
        if ($key === 'hero_exterior') {
            $filename = 'Goetz-Legal-Exterior-1-scaled.png';
        }

        return home_url('/wp-content/uploads/' . $year_month . '/' . $filename);
    };
    $block = static fn(string $name, array $attrs): string => '<!-- wp:' . $name . ' '
        . wp_json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' /-->';

    $hero = $homepage['hero'];
    $content = $block('goetz/hero', [
        'eyebrow'    => $hero['eyebrow'],
        'heading'    => $hero['heading'],
        'content'    => $hero['content'],
        'imageUrl'   => $asset_url('hero_exterior', '2025/03'),
        'imageAlt'   => 'Goetz Legal exterior',
        'buttonText' => $hero['buttonText'],
        'buttonUrl'  => home_url('/james-l-goetz/'),
    ]);
    $content .= '<!-- wp:html --><section class="goetz-intro-section"><div class="goetz-intro">'
        . '<img class="goetz-intro__image" src="' . esc_url($asset_url('welcome_left')) . '" alt="James L. Goetz recognition plaque" loading="lazy">'
        . '<div class="goetz-intro__content"><h2>' . $homepage['welcome']['heading'] . '</h2>'
        . '<img class="goetz-intro__icon" src="' . esc_url($asset_url('scale_icon')) . '" alt="" loading="lazy">'
        . '<p>If you would like to speak with Mr. Goetz, please call <strong>(239) 936-2841</strong> or contact the firm <a href="'
        . esc_url(home_url('/contact/')) . '">online</a>.</p></div>'
        . '<img class="goetz-intro__image" src="' . esc_url($asset_url('welcome_right', '2024/01')) . '" alt="Goetz Legal office library photo" loading="lazy">'
        . '</div></section><!-- /wp:html -->';
    $content .= '<!-- wp:html --><section class="goetz-practice-band"><div class="goetz-practice-band__image">'
        . '<img src="' . esc_url($asset_url('practice_bg')) . '" alt="Law office books and desk" loading="lazy"></div>'
        . '<div class="goetz-practice-band__content"><h2>' . $homepage['practiceAreas']['heading'] . '</h2><ul class="goetz-practice-list">';
    foreach ($homepage['practiceAreas']['items'] as $label) {
        $content .= '<li><span aria-hidden="true"><img src="' . esc_url($asset_url('scale_icon'))
            . '" alt="" loading="lazy"></span><b>' . esc_html((string) $label) . '</b></li>';
    }
    $content .= '</ul></div></section><!-- /wp:html -->';

    $attorneys = $homepage['attorneyGrid']['attorneys'];
    $james_bio = str_replace('father’s', "father's", (string) $attorneys[0]['bio']);
    $content .= '<!-- wp:group {"className":"goetz-section goetz-section--attorneys","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group goetz-section goetz-section--attorneys">'
        . '<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Attorneys</h2><!-- /wp:heading -->'
        . '<div class="goetz-card-grid">'
        . $block('goetz/attorney-card', [
            'name'       => $attorneys[0]['name'],
            'bio'        => $james_bio,
            'imageUrl'   => $asset_url('james_card'),
            'imageAlt'   => 'James L. Goetz',
            'profileUrl' => home_url('/james-l-goetz/'),
        ])
        . $block('goetz/attorney-card', [
            'name'       => $attorneys[1]['name'],
            'bio'        => $attorneys[1]['bio'],
            'imageUrl'   => $asset_url('gregory_card', '2025/03'),
            'imageAlt'   => 'Gregory W. Goetz',
            'profileUrl' => home_url('/gregory-w-goetz/'),
        ])
        . '</div></div><!-- /wp:group --><!-- wp:goetz/cta /-->';

    return $content;
}

$config = require $config_path;
goetz_homepage_migration_assert(is_array($config), 'Homepage migration configuration must return an array.');
$serialized_config = (string) wp_json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
goetz_homepage_migration_assert(
    ! str_contains($serialized_config, '://')
        && ! str_contains($serialized_config, 'localhost')
        && ! str_contains($serialized_config, '/wp-content/uploads/'),
    'Tracked homepage configuration contains an environment-specific URL.'
);

$expected_assets = [
    'hero_exterior' => ['Goetz-Legal-Exterior-1.png', 'image/png', 3072, 3072, '8c3cb4f2f78d9267304c9ee2dfc6e33054f038b9ebd20caef0441246c2d3f5ce'],
    'welcome_left'  => ['PXL_20220818_164549897_2.jpg', 'image/jpeg', 1194, 1138, 'e7710d826d8a5f0dc1e7b53b12eef3d9066530cb671724611d2dd1ed66c1ff6f'],
    'welcome_right' => ['Sue.jpg', 'image/jpeg', 377, 500, 'd1fa1baeffababe91d23313bf85f79c4fcd0864aa93c22ac2a7bb5b75d69b334'],
    'scale_icon'    => ['law-scale-icon-purple.png', 'image/png', 40, 39, '53eade44aed3bacbb4bc00665c43811395ba91a1f1699f338c9e0a07a017cfd0'],
    'practice_bg'   => ['firm-bg.jpg', 'image/jpeg', 1350, 900, '4a96ab8dfde5af0b61a13faa5fd49a29f6f167351d51d2d2461f428208e1c561'],
    'james_card'    => ['JAMES-L.jpg', 'image/jpeg', 1200, 660, '98c56bcab4a5824d6771b29b2ae4547f1097a1a78a788f4c94cc6eff5832e331'],
    'gregory_card'  => ['Greg-Website-Portrait-6.jpg', 'image/jpeg', 1200, 660, '94b15c45d6ef4a152ed45935a06a52671cdff17934da1d7a9e03fdad94092755'],
    'cta_background'=> ['law-updates-bg.jpg', 'image/jpeg', 2304, 1280, '65dc5280c5d08135334fc3a02a9b66616bd517f265a4a2fee4c8904e7f5cb11f'],
    'header_logo'   => ['GoetzLogo.png', 'image/png', 300, 92, 'a2c78c7088101503dafc5c678f2658557e91b061639a4b3ddc309651a74e10ec'],
    'footer_logo'   => ['Goetz-footer-logo.png', 'image/png', 274, 86, '641d06bb5715d2935a6d84d7733a833aabc00a9fc74f335774c764104adb4b01'],
    'social_image'  => ['goetz-social-1200x630.jpg', 'image/jpeg', 1200, 630, '5dc3f7d979c25a2fb269e86b81cd52fc8ec0a2a9049cee48baf6e24a41b4e7f0'],
];

goetz_homepage_migration_assert(
    array_keys($config['assets'] ?? []) === array_keys($expected_assets),
    'Homepage seed keys or their deterministic order changed.'
);

foreach ($expected_assets as $key => [$filename, $mime, $width, $height, $sha256]) {
    $asset = $config['assets'][$key] ?? null;
    goetz_homepage_migration_assert(is_array($asset), "Missing seed configuration for {$key}.");
    foreach ([
        'filename' => $filename,
        'mime'     => $mime,
        'width'    => $width,
        'height'   => $height,
        'sha256'   => $sha256,
    ] as $field => $expected) {
        goetz_homepage_migration_assert(
            ($asset[$field] ?? null) === $expected,
            "Seed configuration changed for {$key}.{$field}."
        );
    }

    $seed_path = GOETZ_SITE_PATH . 'assets/seed/' . $filename;
    goetz_homepage_migration_assert(is_readable($seed_path), "Tracked seed is missing: {$filename}");
    goetz_homepage_migration_assert(
        hash_file('sha256', $seed_path) === $sha256,
        "Tracked seed checksum changed: {$filename}"
    );
    $size = getimagesize($seed_path);
    goetz_homepage_migration_assert(
        is_array($size)
            && (int) ($size[0] ?? 0) === $width
            && (int) ($size[1] ?? 0) === $height
            && (string) ($size['mime'] ?? '') === $mime,
        "Tracked seed dimensions or MIME changed: {$filename}"
    );
}

$migration_class = Goetz\Site\Migrations\Homepage_Migration::class;
$settings_class = Goetz\Site\Settings\Site_Settings::class;
$version_option = $migration_class::VERSION_OPTION;
$page_id_option = $migration_class::PAGE_ID_OPTION;
$theme_mods_option = 'theme_mods_' . get_option('stylesheet');
$option_names = [
    'show_on_front',
    'page_on_front',
    $version_option,
    $page_id_option,
    $migration_class::LOCK_OPTION,
    $settings_class::OPTION_NAME,
    $theme_mods_option,
];
$option_snapshots = [];
foreach ($option_names as $option_name) {
    $option_snapshots[$option_name] = goetz_homepage_snapshot_option($option_name);
}

$initial_attachment_ids = goetz_homepage_attachment_ids();
$initial_attachment_seed_meta = [];
$initial_attachment_checksum_meta = [];
foreach ($initial_attachment_ids as $attachment_id) {
    $initial_attachment_seed_meta[$attachment_id] = get_post_meta(
        $attachment_id,
        Goetz\Site\Migrations\Media_Seeder::META_KEY,
        false
    );
    $initial_attachment_checksum_meta[$attachment_id] = get_post_meta(
        $attachment_id,
        Goetz\Site\Migrations\Media_Seeder::CHECKSUM_META_KEY,
        false
    );
}
$parent_id = 0;
$home_id = 0;
$client_menu_id = 0;
$owned_attachment_ids = [];
$touched_existing_attachment_ids = [];
$owned_menu_ids = [];

try {
    delete_option($version_option);
    delete_option($page_id_option);

    $parent_id = wp_insert_post([
        'post_type'   => 'page',
        'post_status' => 'draft',
        'post_title'  => 'Homepage migration integration parent ' . wp_generate_uuid4(),
        'post_name'   => 'goetz-homepage-migration-parent-' . wp_generate_uuid4(),
    ], true);
    goetz_homepage_migration_assert(! is_wp_error($parent_id), 'Could not create the disposable parent page.');
    $parent_id = (int) $parent_id;

    $legacy_content = goetz_homepage_known_legacy_content($config);
    $home_id = wp_insert_post([
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_parent'  => $parent_id,
        'post_title'   => 'Homepage migration integration target',
        'post_name'    => 'home',
        'post_content' => $legacy_content,
    ], true);
    goetz_homepage_migration_assert(! is_wp_error($home_id), 'Could not create the disposable Home page.');
    $home_id = (int) $home_id;
    goetz_homepage_migration_assert(
        get_post_field('post_name', $home_id) === 'home',
        'Disposable target did not retain the exact required home slug.'
    );

    update_option('show_on_front', 'page');
    update_option('page_on_front', $home_id);

    $client_menu_id = wp_create_nav_menu('Goetz integration client menu ' . wp_generate_uuid4());
    goetz_homepage_migration_assert(! is_wp_error($client_menu_id), 'Could not create the client menu fixture.');
    $client_menu_id = (int) $client_menu_id;
    $owned_menu_ids[] = $client_menu_id;
    set_theme_mod('nav_menu_locations', [
        'primary' => $client_menu_id,
        'footer'  => 0,
    ]);

    $client_image_id = 0;
    foreach ($initial_attachment_ids as $candidate_id) {
        if (wp_attachment_is_image($candidate_id)) {
            $client_image_id = $candidate_id;
            break;
        }
    }
    goetz_homepage_migration_assert($client_image_id > 0, 'An existing image is required for preservation fixtures.');
    set_theme_mod('custom_logo', $client_image_id);

    $settings_fixture = array_merge($settings_class::defaults(), [
        'copyright_dynamic_year' => false,
        'social_image_id'        => 0,
        'integration_probe'      => 'preserve-exactly',
    ]);
    update_option($settings_class::OPTION_NAME, $settings_fixture);

    $migration = new Goetz\Site\Migrations\Homepage_Migration();
    $seeder = new Goetz\Site\Migrations\Media_Seeder();

    $dry_state = [
        'content'          => get_post_field('post_content', $home_id),
        'version'          => get_option($version_option, null),
        'page_id'          => get_option($page_id_option, null),
        'attachments'      => goetz_homepage_attachment_ids(),
        'seed_meta'        => array_map(
            static fn(int $attachment_id): array => get_post_meta(
                $attachment_id,
                Goetz\Site\Migrations\Media_Seeder::META_KEY,
                false
            ),
            goetz_homepage_attachment_ids()
        ),
        'checksum_meta'    => array_map(
            static fn(int $attachment_id): array => get_post_meta(
                $attachment_id,
                Goetz\Site\Migrations\Media_Seeder::CHECKSUM_META_KEY,
                false
            ),
            goetz_homepage_attachment_ids()
        ),
        'upload_tree'      => goetz_homepage_upload_fingerprint(),
        'menus'            => goetz_homepage_menu_ids(),
        'theme_mods'       => get_option($theme_mods_option),
        'settings'         => get_option($settings_class::OPTION_NAME),
        'backup_exists'    => metadata_exists('post', $home_id, $migration_class::BACKUP_META),
    ];
    $plan = $migration->plan();
    $seed_plan = $seeder->seed_all(true);
    goetz_homepage_migration_assert(($plan['status'] ?? '') === 'ready', 'Dry-run did not report the disposable Home page as ready.');
    goetz_homepage_migration_assert(
        ($plan['post_id'] ?? 0) === $home_id,
        'Dry-run targeted a page other than the configured front page.'
    );
    goetz_homepage_migration_assert(
        ($plan['block_order'] ?? []) === [
            'goetz/hero',
            'goetz/welcome',
            'goetz/practice-areas',
            'goetz/attorney-grid',
            'goetz/cta',
        ],
        'Dry-run block order changed.'
    );
    goetz_homepage_migration_assert(count($seed_plan) === 11, 'Dry-run did not plan every curated seed.');
    goetz_homepage_migration_assert(
        $dry_state === [
            'content'       => get_post_field('post_content', $home_id),
            'version'       => get_option($version_option, null),
            'page_id'       => get_option($page_id_option, null),
            'attachments'   => goetz_homepage_attachment_ids(),
            'seed_meta'     => array_map(
                static fn(int $attachment_id): array => get_post_meta(
                    $attachment_id,
                    Goetz\Site\Migrations\Media_Seeder::META_KEY,
                    false
                ),
                goetz_homepage_attachment_ids()
            ),
            'checksum_meta' => array_map(
                static fn(int $attachment_id): array => get_post_meta(
                    $attachment_id,
                    Goetz\Site\Migrations\Media_Seeder::CHECKSUM_META_KEY,
                    false
                ),
                goetz_homepage_attachment_ids()
            ),
            'upload_tree'   => goetz_homepage_upload_fingerprint(),
            'menus'         => goetz_homepage_menu_ids(),
            'theme_mods'    => get_option($theme_mods_option),
            'settings'      => get_option($settings_class::OPTION_NAME),
            'backup_exists' => metadata_exists('post', $home_id, $migration_class::BACKUP_META),
        ],
        'Homepage dry-run changed the database.'
    );

    $blocked_config = $config;
    $blocked_config['menus']['footer']['items'][6]['slug'] = 'missing-integration-page';
    $blocked_state = [
        'content'       => get_post_field('post_content', $home_id),
        'version'       => goetz_homepage_snapshot_option($version_option),
        'page_id'       => goetz_homepage_snapshot_option($page_id_option),
        'attachments'   => goetz_homepage_attachment_ids(),
        'menus'         => goetz_homepage_menu_ids(),
        'theme_mods'    => get_option($theme_mods_option),
        'settings'      => get_option($settings_class::OPTION_NAME),
        'upload_tree'   => goetz_homepage_upload_fingerprint(),
        'backup_exists' => metadata_exists('post', $home_id, $migration_class::BACKUP_META),
    ];
    $blocked_plan = (new Goetz\Site\Migrations\Homepage_Migration($blocked_config))->plan();
    goetz_homepage_migration_assert(
        ($blocked_plan['status'] ?? '') === 'blocked'
            && ($blocked_plan['bootstrap']['status'] ?? '') === 'blocked'
            && ($blocked_plan['bootstrap']['missing_pages'] ?? []) === ['missing-integration-page'],
        'Homepage plan did not propagate a blocked navigation prerequisite.'
    );
    $blocked_state_after = [
            'content'       => get_post_field('post_content', $home_id),
            'version'       => goetz_homepage_snapshot_option($version_option),
            'page_id'       => goetz_homepage_snapshot_option($page_id_option),
            'attachments'   => goetz_homepage_attachment_ids(),
            'menus'         => goetz_homepage_menu_ids(),
            'theme_mods'    => get_option($theme_mods_option),
            'settings'      => get_option($settings_class::OPTION_NAME),
            'upload_tree'   => goetz_homepage_upload_fingerprint(),
            'backup_exists' => metadata_exists('post', $home_id, $migration_class::BACKUP_META),
    ];
    $blocked_changed_keys = array_keys(array_filter(
        $blocked_state,
        static fn($value, $key): bool => $blocked_state_after[$key] !== $value,
        ARRAY_FILTER_USE_BOTH
    ));
    goetz_homepage_migration_assert(
        $blocked_state === $blocked_state_after,
        'Blocked homepage plan wrote to the database or uploads: ' . implode(', ', $blocked_changed_keys)
    );

    $query_content = str_replace(
        home_url('/james-l-goetz/'),
        home_url('/james-l-goetz/?review=1'),
        $legacy_content
    );
    goetz_homepage_migration_assert($query_content !== $legacy_content, 'Query-string conflict fixture did not change legacy content.');
    wp_update_post(wp_slash(['ID' => $home_id, 'post_content' => $query_content]));
    goetz_homepage_migration_assert(
        (($migration->plan()['status'] ?? '') === 'conflict'),
        'A legacy URL containing a query string was accepted.'
    );

    $one_byte_content = substr_replace($legacy_content, '!', 0, 1);
    goetz_homepage_migration_assert(strlen($one_byte_content) === strlen($legacy_content), 'One-byte conflict fixture changed content length.');
    wp_update_post(wp_slash(['ID' => $home_id, 'post_content' => $one_byte_content]));
    goetz_homepage_migration_assert(
        (($migration->plan()['status'] ?? '') === 'conflict'),
        'A one-byte legacy content change was accepted.'
    );
    wp_update_post(wp_slash(['ID' => $home_id, 'post_content' => $legacy_content]));

    $invalid_config = $config;
    $invalid_config['assets']['hero_exterior']['mime'] = 'text/plain';
    $invalid_seeder = new Goetz\Site\Migrations\Media_Seeder($invalid_config);
    goetz_homepage_expect_runtime_exception(
        static fn(): int => $invalid_seeder->seed('hero_exterior'),
        'image MIME'
    );
    goetz_homepage_migration_assert(
        goetz_homepage_attachment_ids() === $dry_state['attachments'],
        'Rejected non-image seed created an attachment.'
    );

    $wrong_dimension_config = $config;
    $wrong_dimension_config['assets']['hero_exterior']['width'] = 1;
    goetz_homepage_expect_runtime_exception(
        static fn(): int => (new Goetz\Site\Migrations\Media_Seeder($wrong_dimension_config))->seed('hero_exterior'),
        'dimension'
    );

    $duplicate_images = array_values(array_filter(
        $initial_attachment_ids,
        static fn(int $id): bool => wp_attachment_is_image($id)
    ));
    goetz_homepage_migration_assert(count($duplicate_images) >= 2, 'Duplicate seed-key coverage requires two image attachments.');
    $hero_seed_key = (string) $config['assets']['hero_exterior']['seed_key'];
    $touched_existing_attachment_ids[] = $duplicate_images[0];
    $touched_existing_attachment_ids[] = $duplicate_images[1];
    add_post_meta($duplicate_images[0], Goetz\Site\Migrations\Media_Seeder::META_KEY, $hero_seed_key);
    add_post_meta($duplicate_images[1], Goetz\Site\Migrations\Media_Seeder::META_KEY, $hero_seed_key);
    goetz_homepage_expect_runtime_exception(
        static fn(): int => $seeder->seed('hero_exterior'),
        'duplicate seed-key'
    );
    delete_post_meta($duplicate_images[0], Goetz\Site\Migrations\Media_Seeder::META_KEY, $hero_seed_key);
    delete_post_meta($duplicate_images[1], Goetz\Site\Migrations\Media_Seeder::META_KEY, $hero_seed_key);

    $tampered_id = 0;
    foreach ($duplicate_images as $candidate_id) {
        $candidate_path = wp_get_original_image_path($candidate_id, true);
        if (! is_string($candidate_path)
            || hash_file('sha256', $candidate_path) !== $expected_assets['hero_exterior'][4]) {
            $tampered_id = $candidate_id;
            break;
        }
    }
    goetz_homepage_migration_assert($tampered_id > 0, 'Tampered seed-key coverage could not find a non-exterior image.');
    $touched_existing_attachment_ids[] = $tampered_id;
    add_post_meta($tampered_id, Goetz\Site\Migrations\Media_Seeder::META_KEY, $hero_seed_key);
    add_post_meta(
        $tampered_id,
        Goetz\Site\Migrations\Media_Seeder::CHECKSUM_META_KEY,
        $expected_assets['hero_exterior'][4]
    );
    goetz_homepage_expect_runtime_exception(
        static fn(): int => $seeder->seed('hero_exterior'),
        'attachment'
    );
    delete_post_meta($tampered_id, Goetz\Site\Migrations\Media_Seeder::META_KEY, $hero_seed_key);
    delete_post_meta(
        $tampered_id,
        Goetz\Site\Migrations\Media_Seeder::CHECKSUM_META_KEY,
        $expected_assets['hero_exterior'][4]
    );

    wp_update_post(['ID' => $home_id, 'post_name' => 'not-home']);
    goetz_homepage_expect_runtime_exception(
        static fn(): array => $migration->apply(),
        'slug home'
    );
    goetz_homepage_migration_assert(
        get_post_field('post_content', $home_id) === $legacy_content
            && get_option($version_option, null) === null
            && get_option($page_id_option, null) === null,
        'Strict target failure changed content or migration state.'
    );
    wp_update_post(['ID' => $home_id, 'post_name' => 'home']);
    goetz_homepage_migration_assert(get_post_field('post_name', $home_id) === 'home', 'Could not restore the disposable home slug.');

    $pre_failure_state = [
        'attachments' => goetz_homepage_attachment_ids(),
        'seed_meta'   => array_map(
            static fn(int $id): array => get_post_meta($id, Goetz\Site\Migrations\Media_Seeder::META_KEY, false),
            goetz_homepage_attachment_ids()
        ),
        'checksum_meta'=> array_map(
            static fn(int $id): array => get_post_meta($id, Goetz\Site\Migrations\Media_Seeder::CHECKSUM_META_KEY, false),
            goetz_homepage_attachment_ids()
        ),
        'menus'       => goetz_homepage_menu_ids(),
        'theme_mods'  => get_option($theme_mods_option),
        'settings'    => get_option($settings_class::OPTION_NAME),
        'uploads'     => goetz_homepage_upload_fingerprint(),
    ];
    $force_big_image_scaling = static fn($threshold): int => 2560;
    add_filter('big_image_size_threshold', $force_big_image_scaling, 999);
    $fail_update = static function (array $data, array $postarr) use ($home_id): array {
        if ((int) ($postarr['ID'] ?? 0) === $home_id
            && isset($postarr['post_content'])
            && (string) $postarr['post_content'] !== '') {
            throw new RuntimeException('Injected homepage update failure.');
        }

        return $data;
    };
    add_filter('wp_insert_post_data', $fail_update, 999, 2);
    goetz_homepage_expect_runtime_exception(
        static fn(): array => $migration->apply(),
        'Injected homepage update failure'
    );
    remove_filter('wp_insert_post_data', $fail_update, 999);
    remove_filter('big_image_size_threshold', $force_big_image_scaling, 999);

    goetz_homepage_migration_assert(
        get_post_field('post_content', $home_id) === $legacy_content,
        'A failed wp_update_post changed the original homepage content.'
    );
    goetz_homepage_migration_assert(
        get_option($version_option, null) === null && get_option($page_id_option, null) === null,
        'Migration version or target ID was written after a failed wp_update_post.'
    );
    goetz_homepage_migration_assert(
        ! metadata_exists('post', $home_id, $migration_class::BACKUP_META),
        'A failed homepage update left a partial backup marker.'
    );
    goetz_homepage_migration_assert(
        $pre_failure_state === [
            'attachments' => goetz_homepage_attachment_ids(),
            'seed_meta'    => array_map(
                static fn(int $id): array => get_post_meta($id, Goetz\Site\Migrations\Media_Seeder::META_KEY, false),
                goetz_homepage_attachment_ids()
            ),
            'checksum_meta'=> array_map(
                static fn(int $id): array => get_post_meta($id, Goetz\Site\Migrations\Media_Seeder::CHECKSUM_META_KEY, false),
                goetz_homepage_attachment_ids()
            ),
            'menus'        => goetz_homepage_menu_ids(),
            'theme_mods'   => get_option($theme_mods_option),
            'settings'     => get_option($settings_class::OPTION_NAME),
            'uploads'      => goetz_homepage_upload_fingerprint(),
        ],
        'Failed homepage apply did not compensate its media/bootstrap writes.'
    );

    $foreign_lock_token = 'foreign-owner-' . wp_generate_uuid4();
    goetz_homepage_migration_assert(
        add_option($migration_class::LOCK_OPTION, $foreign_lock_token, '', false),
        'Could not create the foreign migration-lock fixture.'
    );
    goetz_homepage_expect_runtime_exception(
        static fn(): array => $migration->apply(),
        'already running'
    );
    goetz_homepage_migration_assert(
        get_option($migration_class::LOCK_OPTION) === $foreign_lock_token
            && get_post_field('post_content', $home_id) === $legacy_content,
        'A contending migration changed data or released another owner’s lock.'
    );
    delete_option($migration_class::LOCK_OPTION);

    $concurrent_content = $legacy_content
        . '<!-- wp:paragraph --><p>Concurrent editor content survives.</p><!-- /wp:paragraph -->';
    $concurrent_version = 77;
    $inject_concurrent_change = static function ($meta_id, $object_id, $meta_key) use (
        $home_id,
        $migration_class,
        $concurrent_content,
        $version_option,
        $concurrent_version
    ): void {
        if ((int) $object_id !== $home_id || $meta_key !== $migration_class::BACKUP_META) {
            return;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            ['post_content' => $concurrent_content],
            ['ID' => $home_id],
            ['%s'],
            ['%d']
        );
        clean_post_cache($home_id);
        update_option($version_option, $concurrent_version);
    };
    add_action('added_post_meta', $inject_concurrent_change, 10, 3);
    goetz_homepage_expect_runtime_exception(
        static fn(): array => $migration->apply(),
        'changed while the migration was preparing its update'
    );
    remove_action('added_post_meta', $inject_concurrent_change, 10);
    goetz_homepage_migration_assert(
        get_post_field('post_content', $home_id) === $concurrent_content,
        'Rollback overwrote a concurrent homepage content change.'
    );
    goetz_homepage_migration_assert(
        (int) get_option($version_option) === $concurrent_version,
        'Rollback overwrote a concurrent migration-option change.'
    );
    goetz_homepage_migration_assert(
        ! metadata_exists('post', $home_id, $migration_class::BACKUP_META)
            && ! metadata_exists('post', $home_id, $migration_class::CONTENT_HASH_META)
            && ! goetz_homepage_snapshot_option($page_id_option)['exists'],
        'Failed concurrent-change detection left migration-owned markers behind.'
    );
    goetz_homepage_migration_assert(
        get_option($migration_class::LOCK_OPTION, null) === null,
        'Migration lock was not released after a guarded failure.'
    );
    delete_option($version_option);
    wp_update_post(wp_slash(['ID' => $home_id, 'post_content' => $legacy_content]));

    $change_status_after_update = static function ($post_id) use ($home_id): void {
        if ((int) $post_id !== $home_id) {
            return;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            ['post_status' => 'draft'],
            ['ID' => $home_id],
            ['%s'],
            ['%d']
        );
        clean_post_cache($home_id);
    };
    add_action('post_updated', $change_status_after_update, 999, 1);
    goetz_homepage_expect_runtime_exception(
        static fn(): array => $migration->apply(),
        'target verification failed after update'
    );
    remove_action('post_updated', $change_status_after_update, 999);
    goetz_homepage_migration_assert(
        get_post_field('post_status', $home_id) === 'draft'
            && ! goetz_homepage_snapshot_option($version_option)['exists']
            && ! goetz_homepage_snapshot_option($page_id_option)['exists']
            && ! metadata_exists('post', $home_id, $migration_class::BACKUP_META)
            && ! metadata_exists('post', $home_id, $migration_class::CONTENT_HASH_META)
            && ! goetz_homepage_snapshot_option($migration_class::LOCK_OPTION)['exists'],
        'Post-update target drift was accepted or left migration-owned state behind.'
    );
    global $wpdb;
    $wpdb->update(
        $wpdb->posts,
        ['post_content' => $legacy_content, 'post_status' => 'publish'],
        ['ID' => $home_id],
        ['%s', '%s'],
        ['%d']
    );
    clean_post_cache($home_id);

    $exterior_id = $seeder->seed('hero_exterior');
    if (in_array($exterior_id, $initial_attachment_ids, true)) {
        $touched_existing_attachment_ids[] = $exterior_id;
    } else {
        $owned_attachment_ids[] = $exterior_id;
    }
    goetz_homepage_migration_assert($exterior_id > 0, 'Exterior seed did not resolve after the guarded failure.');
    goetz_homepage_migration_assert(
        $seeder->seed('hero_exterior') === $exterior_id,
        'Seed-key lookup did not reuse the existing exterior attachment.'
    );
    $exterior_original = wp_get_original_image_path($exterior_id);
    $exterior_attached = get_attached_file($exterior_id);
    goetz_homepage_migration_assert(
        is_string($exterior_original)
            && is_readable($exterior_original)
            && hash_file('sha256', $exterior_original) === $expected_assets['hero_exterior'][4],
        'Scaled exterior validation did not hash the original image bytes.'
    );
    goetz_homepage_migration_assert(
        is_string($exterior_attached)
            && is_readable($exterior_attached)
            && $exterior_original !== $exterior_attached
            && str_contains(basename($exterior_attached), '-scaled.'),
        'The integration fixture did not exercise WordPress big-image scaling.'
    );

    $write_order = [];
    $record_post_update = static function ($post_id) use (&$write_order, $home_id): void {
        if ((int) $post_id === $home_id) {
            $write_order[] = 'post_content';
        }
    };
    $record_meta = static function ($meta_id, $post_id, $meta_key) use (&$write_order, $home_id, $migration_class): void {
        if ((int) $post_id === $home_id && $meta_key === $migration_class::CONTENT_HASH_META) {
            $write_order[] = 'content_hash';
        }
    };
    $record_option = static function (string $option) use (&$write_order, $version_option, $page_id_option): void {
        if ($option === $page_id_option) {
            $write_order[] = 'page_id';
        }
        if ($option === $version_option) {
            $write_order[] = 'version';
        }
    };
    add_action('post_updated', $record_post_update, 10, 1);
    add_action('added_post_meta', $record_meta, 10, 3);
    add_action('updated_post_meta', $record_meta, 10, 3);
    add_action('added_option', $record_option, 10, 1);
    add_action('updated_option', $record_option, 10, 1);
    $first = $migration->apply();
    remove_action('post_updated', $record_post_update, 10);
    remove_action('added_post_meta', $record_meta, 10);
    remove_action('updated_post_meta', $record_meta, 10);
    remove_action('added_option', $record_option, 10);
    remove_action('updated_option', $record_option, 10);

    foreach (array_map('intval', $first['media'] ?? []) as $seeded_id) {
        if (in_array($seeded_id, $initial_attachment_ids, true)) {
            $touched_existing_attachment_ids[] = $seeded_id;
        } else {
            $owned_attachment_ids[] = $seeded_id;
        }
    }
    foreach (array_map('intval', $first['bootstrap']['created_menu_ids'] ?? []) as $created_menu_id) {
        $owned_menu_ids[] = $created_menu_id;
    }

    goetz_homepage_migration_assert(($first['status'] ?? '') === 'migrated', 'First homepage apply did not migrate.');
    goetz_homepage_migration_assert(
        get_post_meta($home_id, $migration_class::BACKUP_META, true) === $legacy_content,
        'Successful apply did not preserve the exact original homepage content once.'
    );
    goetz_homepage_migration_assert(get_option($version_option) === 1, 'First apply did not write schema version 1.');
    goetz_homepage_migration_assert((int) get_option($page_id_option) === $home_id, 'First apply did not record the managed page ID.');
    goetz_homepage_migration_assert(end($write_order) === 'version', 'Homepage schema version was not written last.');
    foreach (['post_content', 'content_hash', 'page_id', 'version'] as $required_event) {
        goetz_homepage_migration_assert(in_array($required_event, $write_order, true), "Missing write-order event: {$required_event}");
    }

    $migrated_content = (string) get_post_field('post_content', $home_id);
    $blocks = array_values(array_filter(
        parse_blocks($migrated_content),
        static fn(array $block): bool => $block['blockName'] !== null
    ));
    goetz_homepage_migration_assert(
        array_column($blocks, 'blockName') === [
            'goetz/hero',
            'goetz/welcome',
            'goetz/practice-areas',
            'goetz/attorney-grid',
            'goetz/cta',
        ],
        'Migrated homepage root order changed.'
    );
    goetz_homepage_migration_assert(
        serialize_blocks($blocks) === $migrated_content,
        'Migrated homepage did not survive a canonical parse/serialize round trip.'
    );
    goetz_homepage_migration_assert(
        serialize_blocks(parse_blocks($migrated_content)) === $migrated_content,
        'Unfiltered homepage parsing introduced freeform or normalization bytes.'
    );
    foreach ($blocks as $block) {
        goetz_homepage_assert_named_tree($block);
        goetz_homepage_migration_assert(
            ($block['attrs']['lock'] ?? null) === ['move' => true, 'remove' => true],
            "Root block is not move/remove locked: {$block['blockName']}"
        );
    }

    $practice_children = $blocks[2]['innerBlocks'] ?? [];
    goetz_homepage_migration_assert(
        array_column($practice_children, 'blockName') === array_fill(0, 7, 'goetz/practice-area-item'),
        'Practice Areas children changed.'
    );
    goetz_homepage_migration_assert(
        array_map(static fn(array $block): string => (string) ($block['attrs']['label'] ?? ''), $practice_children)
            === ['Corporate', 'Construction', 'Real Estate', 'Probate', 'Criminal', 'Bankruptcy', 'Appeals'],
        'Practice Areas labels changed.'
    );
    foreach ($practice_children as $child) {
        goetz_homepage_migration_assert(! isset($child['attrs']['lock']), 'A Practice Areas child was incorrectly locked.');
    }

    $attorney_children = $blocks[3]['innerBlocks'] ?? [];
    goetz_homepage_migration_assert(
        array_column($attorney_children, 'blockName') === ['goetz/attorney-card', 'goetz/attorney-card'],
        'Attorney Grid children changed.'
    );
    foreach ($attorney_children as $child) {
        goetz_homepage_migration_assert(! isset($child['attrs']['lock']), 'An Attorney Grid child was incorrectly locked.');
    }

    foreach ([
        'eyebrow'      => 'GoetzLegal.com',
        'heading'      => 'A law firm with<br>seasoned trial<br>attorneys <b>in</b><br><b>Fort Myers,<br>Florida.</b>',
        'content'      => 'Goetz & Goetz represents all individuals who need legal advice in regards to corporate, construction, real estate, probate, criminal and bankruptcy matters. Goetz & Goetz has been a legal resource in Fort Myers for over 50 years and has a vast amount of legal experience at your disposal.',
        'buttonText'   => 'Learn More About Us',
        'buttonUrl'    => '/james-l-goetz/',
        'buttonNewTab' => false,
    ] as $key => $value) {
        goetz_homepage_migration_assert(($blocks[0]['attrs'][$key] ?? null) === $value, "Canonical Hero changed: {$key}");
    }
    foreach ([
        'phoneLabel'  => '',
        'phoneUrl'    => '',
        'onlineLabel' => 'online',
        'onlineUrl'   => '',
    ] as $key => $value) {
        goetz_homepage_migration_assert(($blocks[1]['attrs'][$key] ?? null) === $value, "Canonical Welcome changed: {$key}");
    }
    foreach ([
        ['James L. Goetz', 'James L. Goetz was born in Erie, Pennsylvania. He grew up in Oil City and Girard, Pennsylvania working on his father’s farm and coal mines until he went to college.', '/james-l-goetz/'],
        ['Gregory W. Goetz', 'Mr. Gregory W. Goetz was born and raised here in Fort Myers, Florida. He attended Fort Myers High School and then was accepted to University of Florida.', '/gregory-w-goetz/'],
    ] as $index => [$name, $bio, $profile_url]) {
        goetz_homepage_migration_assert(
            ($attorney_children[$index]['attrs']['name'] ?? '') === $name
                && ($attorney_children[$index]['attrs']['bio'] ?? '') === $bio
                && ($attorney_children[$index]['attrs']['profileUrl'] ?? '') === $profile_url,
            "Canonical attorney entry changed at index {$index}."
        );
    }
    foreach ([
        'eyebrow'     => 'WE ARE AN EXPERIENCED TEAM',
        'heading'     => 'NEED A LAWYER?',
        'buttonText'  => '',
        'buttonUrl'   => '',
        'buttonNewTab'=> false,
    ] as $key => $value) {
        goetz_homepage_migration_assert(($blocks[4]['attrs'][$key] ?? null) === $value, "Canonical CTA changed: {$key}");
    }

    goetz_homepage_assert_portable_image($blocks[0]['attrs'], 'imageId', 'imageUrl', 'Hero image');
    goetz_homepage_assert_portable_image($blocks[1]['attrs'], 'leftImageId', 'leftImageUrl', 'Welcome left image');
    goetz_homepage_assert_portable_image($blocks[1]['attrs'], 'rightImageId', 'rightImageUrl', 'Welcome right image');
    goetz_homepage_assert_portable_image($blocks[2]['attrs'], 'backgroundImageId', 'backgroundImageUrl', 'Practice background');
    goetz_homepage_assert_portable_image($blocks[2]['attrs'], 'scaleImageId', 'scaleImageUrl', 'Practice scale icon');
    goetz_homepage_assert_portable_image($attorney_children[0]['attrs'], 'imageId', 'imageUrl', 'James card image');
    goetz_homepage_assert_portable_image($attorney_children[1]['attrs'], 'imageId', 'imageUrl', 'Gregory card image');
    goetz_homepage_assert_portable_image($blocks[4]['attrs'], 'backgroundImageId', 'backgroundImageUrl', 'CTA background');

    $locations = get_theme_mod('nav_menu_locations', []);
    goetz_homepage_migration_assert(
        (int) ($locations['primary'] ?? 0) === $client_menu_id,
        'Bootstrap overwrote the client-assigned primary menu.'
    );
    goetz_homepage_migration_assert(
        (int) ($locations['footer'] ?? 0) > 0,
        'Bootstrap did not fill the empty footer menu location.'
    );
    goetz_homepage_assert_native_menu(
        (int) $locations['footer'],
        $config['menus']['footer']['items'],
        $home_id
    );
    goetz_homepage_migration_assert(
        (int) get_theme_mod('custom_logo', 0) === $client_image_id,
        'Bootstrap overwrote the client Custom Logo.'
    );
    $settings_after_apply = get_option($settings_class::OPTION_NAME);
    goetz_homepage_migration_assert(
        is_array($settings_after_apply)
            && ($settings_after_apply['copyright_dynamic_year'] ?? null) === false
            && ($settings_after_apply['integration_probe'] ?? '') === 'preserve-exactly'
            && (int) ($settings_after_apply['social_image_id'] ?? 0) > 0,
        'Bootstrap did not merge only the empty social image setting.'
    );
    $social_id = (int) $settings_after_apply['social_image_id'];
    $social_path = wp_get_original_image_path($social_id);
    $social_size = is_string($social_path) ? getimagesize($social_path) : false;
    goetz_homepage_migration_assert(
        is_array($social_size) && (int) $social_size[0] === 1200 && (int) $social_size[1] === 630,
        'Curated social attachment is not exactly 1200x630.'
    );

    $noop_state = [
        'content'          => $migrated_content,
        'modified'         => get_post_field('post_modified_gmt', $home_id),
        'attachments'      => goetz_homepage_attachment_ids(),
        'menus'            => goetz_homepage_menu_ids(),
        'theme_mods'       => get_option($theme_mods_option),
        'settings'         => get_option($settings_class::OPTION_NAME),
        'backup'           => get_post_meta($home_id, $migration_class::BACKUP_META, true),
        'content_hash'     => get_post_meta($home_id, $migration_class::CONTENT_HASH_META, true),
        'version'          => get_option($version_option),
        'page_id'          => get_option($page_id_option),
    ];
    $second = $migration->apply();
    goetz_homepage_migration_assert(($second['status'] ?? '') === 'noop', 'Second homepage apply was not a no-op.');
    goetz_homepage_migration_assert(
        $noop_state === [
            'content'      => get_post_field('post_content', $home_id),
            'modified'     => get_post_field('post_modified_gmt', $home_id),
            'attachments'  => goetz_homepage_attachment_ids(),
            'menus'        => goetz_homepage_menu_ids(),
            'theme_mods'   => get_option($theme_mods_option),
            'settings'     => get_option($settings_class::OPTION_NAME),
            'backup'       => get_post_meta($home_id, $migration_class::BACKUP_META, true),
            'content_hash' => get_post_meta($home_id, $migration_class::CONTENT_HASH_META, true),
            'version'      => get_option($version_option),
            'page_id'      => get_option($page_id_option),
        ],
        'Normal homepage rerun wrote to the database.'
    );

    $editor_content = $migrated_content . '<!-- wp:paragraph --><p>Client edit survives.</p><!-- /wp:paragraph -->';
    wp_update_post(wp_slash(['ID' => $home_id, 'post_content' => $editor_content]));
    $managed_modified = $migration->apply();
    goetz_homepage_migration_assert(
        ($managed_modified['status'] ?? '') === 'managed_modified'
            && get_post_field('post_content', $home_id) === $editor_content,
        'Normal rerun overwrote a post-version editor change.'
    );

    $stored_hash = get_post_meta($home_id, $migration_class::CONTENT_HASH_META, true);
    wp_update_post(wp_slash(['ID' => $home_id, 'post_content' => $migrated_content]));
    delete_post_meta($home_id, $migration_class::CONTENT_HASH_META);
    $inconsistent = $migration->apply();
    goetz_homepage_migration_assert(
        ($inconsistent['status'] ?? '') === 'inconsistent'
            && get_post_field('post_content', $home_id) === $migrated_content,
        'Incomplete managed state did not fail closed.'
    );
    update_post_meta($home_id, $migration_class::CONTENT_HASH_META, $stored_hash);

    wp_update_post(wp_slash(['ID' => $home_id, 'post_content' => $editor_content]));
    $backup_before_force = get_post_meta($home_id, $migration_class::BACKUP_META, true);
    $forced = $migration->apply(true);
    goetz_homepage_migration_assert(
        ($forced['status'] ?? '') === 'recovered'
            && get_post_field('post_content', $home_id) === $migrated_content
            && get_post_meta($home_id, $migration_class::BACKUP_META, true) === $backup_before_force,
        'Explicit recovery did not restore canonical content while preserving the original backup.'
    );

    $front_context = new WP_Block_Editor_Context(['post' => get_post($home_id)]);
    $front_settings = Goetz\Site\Editor\Homepage_Editor::filter_settings(
        ['templateLock' => false, 'integration' => 'preserved'],
        $front_context
    );
    goetz_homepage_migration_assert(
        ($front_settings['templateLock'] ?? null) === 'all'
            && ($front_settings['canLockBlocks'] ?? null) === false
            && ($front_settings['codeEditingEnabled'] ?? null) === false
            && ($front_settings['integration'] ?? '') === 'preserved',
        'Configured front-page editor did not receive the protected root settings.'
    );
    $other_context = new WP_Block_Editor_Context(['post' => get_post($parent_id)]);
    $other_settings = Goetz\Site\Editor\Homepage_Editor::filter_settings(
        ['templateLock' => false],
        $other_context
    );
    goetz_homepage_migration_assert(
        ($other_settings['templateLock'] ?? null) === false,
        'Homepage root lock leaked onto another page.'
    );

    $bootstrap = new Goetz\Site\Migrations\Site_Bootstrap();
    $bad_config = $config;
    $bad_config['menus']['footer']['items'][6]['slug'] = 'missing-integration-page';
    set_theme_mod('nav_menu_locations', ['primary' => 0, 'footer' => 0]);
    $blocked_menu_state = [
        'menus'       => goetz_homepage_menu_ids(),
        'attachments' => goetz_homepage_attachment_ids(),
        'theme_mods'  => get_option($theme_mods_option),
        'settings'    => get_option($settings_class::OPTION_NAME),
    ];
    goetz_homepage_expect_runtime_exception(
        static fn(): array => (new Goetz\Site\Migrations\Site_Bootstrap($bad_config))->apply(),
        'requires every configured navigation page'
    );
    goetz_homepage_migration_assert(
        $blocked_menu_state === [
            'menus'        => goetz_homepage_menu_ids(),
            'attachments'  => goetz_homepage_attachment_ids(),
            'theme_mods'   => get_option($theme_mods_option),
            'settings'     => get_option($settings_class::OPTION_NAME),
        ],
        'Missing navigation page created a partial menu or bootstrap write.'
    );

    $settings_with_client_social = get_option($settings_class::OPTION_NAME);
    $settings_with_client_social['social_image_id'] = $client_image_id;
    update_option($settings_class::OPTION_NAME, $settings_with_client_social);
    set_theme_mod('nav_menu_locations', [
        'primary' => 0,
        'footer'  => (int) ($locations['footer'] ?? 0),
    ]);
    remove_theme_mod('custom_logo');
    $bootstrap_result = $bootstrap->apply();
    foreach (array_map('intval', $bootstrap_result['created_menu_ids'] ?? []) as $created_menu_id) {
        $owned_menu_ids[] = $created_menu_id;
    }
    $filled_locations = get_theme_mod('nav_menu_locations', []);
    goetz_homepage_migration_assert(
        ($bootstrap_result['status'] ?? '') === 'updated'
            && (int) ($filled_locations['primary'] ?? 0) > 0
            && (int) ($filled_locations['footer'] ?? 0) === (int) ($locations['footer'] ?? 0),
        'Bootstrap did not fill only the empty primary menu location.'
    );
    goetz_homepage_assert_native_menu(
        (int) $filled_locations['primary'],
        $config['menus']['primary']['items'],
        $home_id
    );
    goetz_homepage_assert_native_menu(
        (int) $filled_locations['footer'],
        $config['menus']['footer']['items'],
        $home_id
    );
    goetz_homepage_migration_assert(
        (int) get_theme_mod('custom_logo', 0) === $seeder->seed('header_logo'),
        'Bootstrap did not fill an empty Custom Logo from the curated seed.'
    );
    $preserved_social_settings = get_option($settings_class::OPTION_NAME);
    goetz_homepage_migration_assert(
        (int) ($preserved_social_settings['social_image_id'] ?? 0) === $client_image_id
            && ($preserved_social_settings['copyright_dynamic_year'] ?? null) === false,
        'Bootstrap overwrote a client social image or a false-valued setting.'
    );

    set_theme_mod('nav_menu_locations', [
        'primary' => $client_menu_id,
        'footer'  => $client_menu_id,
    ]);
    $bootstrap->apply();
    $preserved_locations = get_theme_mod('nav_menu_locations', []);
    goetz_homepage_migration_assert(
        (int) ($preserved_locations['primary'] ?? 0) === $client_menu_id
            && (int) ($preserved_locations['footer'] ?? 0) === $client_menu_id,
        'Bootstrap overwrote populated client menu locations.'
    );

    goetz_homepage_migration_assert(
        has_filter(
            'block_editor_settings_all',
            [Goetz\Site\Editor\Homepage_Editor::class, 'filter_settings']
        ) !== false,
        'Homepage editor lock filter is not registered.'
    );
    if (defined('WP_CLI') && WP_CLI) {
        $deferred = WP_CLI::get_deferred_additions();
        goetz_homepage_migration_assert(
            isset($deferred['goetz-site migrate homepage']),
            'The exact homepage migration WP-CLI command is not registered.'
        );
    }
} finally {
    if ($home_id > 0) {
        wp_delete_post($home_id, true);
    }
    if ($parent_id > 0) {
        wp_delete_post($parent_id, true);
    }

    goetz_homepage_remove_owned_menus($owned_menu_ids);
    goetz_homepage_restore_attachments(
        $owned_attachment_ids,
        $initial_attachment_seed_meta,
        $initial_attachment_checksum_meta,
        $touched_existing_attachment_ids
    );

    foreach ($option_snapshots as $option_name => $snapshot) {
        goetz_homepage_restore_option($option_name, $snapshot);
    }

    clean_post_cache(0);
    wp_cache_flush();
}

fwrite(STDOUT, "Homepage migration checks passed.\n");
