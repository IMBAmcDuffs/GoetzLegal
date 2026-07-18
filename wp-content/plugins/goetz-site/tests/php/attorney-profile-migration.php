<?php

if (! defined('ABSPATH')) {
    fwrite(STDERR, "attorney-profile-migration.php must run through WP-CLI.\n");
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
        "Refusing mutating attorney-profile integration checks outside an explicit loopback local/test environment.\n"
    );
    exit(1);
}

/**
 * @param bool $condition
 */
function goetz_attorney_migration_assert($condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Reproduce the exact imported Gutenberg shape accepted by the guarded
 * attorney-profile migration.
 *
 * @param array<string, mixed> $profile
 */
function goetz_attorney_legacy_content(array $profile): string
{
    $attributes = [
        'name'     => (string) ($profile['name'] ?? ''),
        'email'    => (string) ($profile['legacyEmail'] ?? $profile['email'] ?? ''),
        'imageUrl' => (string) ($profile['legacyImageUrl'] ?? ''),
        'imageAlt' => (string) ($profile['legacyImageAlt'] ?? ''),
    ];
    $bio = (string) ($profile['legacyBio'] ?? $profile['bio'] ?? '');
    $email_label = (string) ($profile['legacyEmailLabel'] ?? 'Email ' . $attributes['name']);

    return '<!-- wp:group {"className":"goetz-section","layout":{"type":"constrained"}} -->'
        . '<div class="wp-block-group goetz-section">'
        . '<!-- wp:goetz/attorney-card '
        . wp_json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . ' /-->'
        . '<!-- wp:paragraph --><p>' . esc_html($bio) . '</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>' . esc_html($email_label) . '</p><!-- /wp:paragraph -->'
        . '</div><!-- /wp:group -->'
        . '<!-- wp:goetz/cta /-->';
}

goetz_attorney_migration_assert(
    method_exists('Goetz\\Site\\Attorney_Profiles', 'apply_to_post'),
    'The guarded attorney-profile content migration is missing.'
);
goetz_attorney_migration_assert(
    method_exists('Goetz\\Site\\Attorney_Profiles', 'profile_for_slug'),
    'The tracked James profile configuration is missing.'
);
goetz_attorney_migration_assert(
    method_exists('Goetz\\Site\\Attorney_Profiles', 'plan_slug'),
    'The read-only attorney-profile migration planner is missing.'
);
goetz_attorney_migration_assert(
    method_exists('Goetz\\Site\\Attorney_Profiles', 'plan_post'),
    'The deterministic attorney-profile post planner is missing.'
);
goetz_attorney_migration_assert(
    method_exists('Goetz\\Site\\Attorney_Profiles', 'verify_slug'),
    'The fail-closed attorney-profile release verifier is missing.'
);
goetz_attorney_migration_assert(
    method_exists('Goetz\\Site\\Attorney_Profiles', 'verify_post'),
    'The deterministic attorney-profile post verifier is missing.'
);
goetz_attorney_migration_assert(
    method_exists('Goetz\\Site\\Attorney_Profiles', 'ensure_media_for_slug'),
    'The repository-backed attorney portrait seeder is missing.'
);

$portrait_seed = WP_CONTENT_DIR . '/plugins/goetz-site/assets/seed/JAMES-L-2.jpg';
goetz_attorney_migration_assert(is_readable($portrait_seed), 'The exact James portrait seed is missing.');
goetz_attorney_migration_assert(
    hash_file('sha256', $portrait_seed) === '56678363da05812f16be9f34a3ffb13ca450fc33a848022c5ea69f35b7c6fadc',
    'The exact James portrait seed changed unexpectedly.'
);

$seeded_portrait_id = Goetz\Site\Attorney_Profiles::ensure_media_for_slug('james-l-goetz');
goetz_attorney_migration_assert($seeded_portrait_id > 0, 'The exact James portrait was not seeded into the Media Library.');
goetz_attorney_migration_assert(
    Goetz\Site\Attorney_Profiles::ensure_media_for_slug('james-l-goetz') === $seeded_portrait_id,
    'The James portrait seeder is not idempotent.'
);

$tampered_upload = wp_upload_bits(
    'tampered-james-profile.jpg',
    null,
    (string) file_get_contents($portrait_seed) . 'tampered'
);
goetz_attorney_migration_assert(empty($tampered_upload['error']), 'Could not create the tampered portrait fixture.');
$tampered_portrait_id = wp_insert_attachment([
    'post_mime_type' => 'image/jpeg',
    'post_title'     => 'Tampered James portrait fixture',
    'post_status'    => 'inherit',
], (string) $tampered_upload['file']);
goetz_attorney_migration_assert(! is_wp_error($tampered_portrait_id), 'Could not register the tampered portrait fixture.');
$tampered_portrait_id = (int) $tampered_portrait_id;
update_post_meta($tampered_portrait_id, '_goetz_attorney_profile_seed_key', 'james-l-goetz:portrait:v1');

try {
    $checksum_guarded_profile = Goetz\Site\Attorney_Profiles::profile_for_slug('james-l-goetz');
    goetz_attorney_migration_assert(
        is_array($checksum_guarded_profile)
            && (int) ($checksum_guarded_profile['imageId'] ?? 0) === 0,
        'Profile resolution did not fail closed for duplicate or tampered portrait ownership metadata.'
    );
    $attachment_count_with_duplicate = (int) wp_count_posts('attachment')->inherit;
    goetz_attorney_migration_assert(
        Goetz\Site\Attorney_Profiles::ensure_media_for_slug('james-l-goetz') === 0
            && (int) wp_count_posts('attachment')->inherit === $attachment_count_with_duplicate,
        'Portrait seeding did not reject duplicate ownership without creating more media.'
    );
} finally {
    wp_delete_attachment($tampered_portrait_id, true);
}
goetz_attorney_migration_assert(
    (int) (Goetz\Site\Attorney_Profiles::profile_for_slug('james-l-goetz')['imageId'] ?? 0)
        === $seeded_portrait_id,
    'The verified James portrait did not recover after the duplicate ownership fixture was removed.'
);

$duplicate_seed_meta_id = add_post_meta(
    $seeded_portrait_id,
    '_goetz_attorney_profile_seed_key',
    'james-l-goetz:portrait:v1',
    false
);
goetz_attorney_migration_assert(
    is_int($duplicate_seed_meta_id) && $duplicate_seed_meta_id > 0,
    'Could not create the duplicate portrait ownership-row fixture.'
);
try {
    goetz_attorney_migration_assert(
        (int) (Goetz\Site\Attorney_Profiles::profile_for_slug('james-l-goetz')['imageId'] ?? 0) === 0
            && Goetz\Site\Attorney_Profiles::ensure_media_for_slug('james-l-goetz') === 0,
        'Portrait ownership accepted duplicate seed rows on one attachment.'
    );
} finally {
    delete_metadata_by_mid('post', $duplicate_seed_meta_id);
}
goetz_attorney_migration_assert(
    Goetz\Site\Attorney_Profiles::ensure_media_for_slug('james-l-goetz') === $seeded_portrait_id,
    'The verified portrait ownership did not recover after removing the duplicate seed row.'
);

$deferred_commands = WP_CLI::get_deferred_additions();
goetz_attorney_migration_assert(
    isset($deferred_commands['goetz-site attorney-profile']),
    'The WP-CLI attorney-profile migration command is not registered.'
);

$unknown_plan = Goetz\Site\Attorney_Profiles::plan_slug('not-a-configured-profile');
goetz_attorney_migration_assert(
    ($unknown_plan['status'] ?? '') === 'unknown_profile',
    'Unknown profile planning did not fail closed.'
);

$james = Goetz\Site\Attorney_Profiles::profile_for_slug('james-l-goetz');
goetz_attorney_migration_assert(is_array($james), 'The James profile configuration could not be resolved.');
$expected_james_bio = 'James L. Goetz was born in Erie, Pennsylvania. He grew up in Oil City and Girard, Pennsylvania working on his father’s farm and coal mines until he went to college. Mr. Goetz’s received his B.A. in political science and a minor in economics from the University of Pittsburgh in 1969. He earned his Juris Doctorate from University of Akron in 1972. From 1972 to 1975, Mr. Goetz served as a Captain in the Judge Advocate General Corps of the United States Army. Mr. Goetz later moved to Fort Myers to begin practicing law at Roberts and Watson, where he later became a partner. Mr. Goetz has been practicing law in Fort Myers for more than 50 years. Mr. Goetz’s Practice Areas include: Estates, Real Estate, Trial, Probate, Construction Law, Bankruptcy, and Commercial Litigation. Mr. Goetz was admitted to the Ohio Bar, Florida Bar, and U.S. Court of Military Appeals in 1972, U.S. Supreme Court in 1976, and also admitted to practice in the United States District Court, Middle District of Florida. Mr. Goetz is a member of the Florida and Ohio State Bar and is also a member of the Lee County Bar association.';
goetz_attorney_migration_assert(
    ($james['bio'] ?? '') === $expected_james_bio,
    'The James biography no longer matches the public source exactly.'
);
foreach ([
    'name'             => 'James L. Goetz',
    'email'            => 'info@goetzlegal.com',
    'imageAlt'         => 'James L. Goetz portrait',
    'legacyEmailLabel' => 'Email James L. Goetz',
] as $key => $value) {
    goetz_attorney_migration_assert(($james[$key] ?? null) === $value, "James profile configuration changed: {$key}");
}
goetz_attorney_migration_assert((int) ($james['imageId'] ?? 0) > 0, 'The James portrait attachment was not resolved.');
goetz_attorney_migration_assert(
    str_ends_with((string) ($james['imageUrl'] ?? ''), '/JAMES-L-2.jpg'),
    'The James profile does not use the exact current Media Library portrait.'
);

$gregory_portrait_seed = WP_CONTENT_DIR . '/plugins/goetz-site/assets/seed/Greg-Website-Portrait-6.jpg';
goetz_attorney_migration_assert(is_readable($gregory_portrait_seed), 'The exact Gregory portrait seed is missing.');
goetz_attorney_migration_assert(
    hash_file('sha256', $gregory_portrait_seed) === '94b15c45d6ef4a152ed45935a06a52671cdff17934da1d7a9e03fdad94092755',
    'The exact Gregory portrait seed changed unexpectedly.'
);

$seeded_gregory_portrait_id = Goetz\Site\Attorney_Profiles::ensure_media_for_slug('gregory-w-goetz');
goetz_attorney_migration_assert(
    $seeded_gregory_portrait_id > 0,
    'The exact Gregory portrait was not seeded into the Media Library.'
);
goetz_attorney_migration_assert(
    Goetz\Site\Attorney_Profiles::ensure_media_for_slug('gregory-w-goetz') === $seeded_gregory_portrait_id,
    'The Gregory portrait seeder is not idempotent.'
);

$gregory = Goetz\Site\Attorney_Profiles::profile_for_slug('gregory-w-goetz');
goetz_attorney_migration_assert(is_array($gregory), 'The Gregory profile configuration could not be resolved.');
$expected_gregory_bio = 'Mr. Gregory W. Goetz was born and raised here in Fort Myers, Florida. He attended Fort Myers High School and then was accepted to University of Florida. Mr. Goetz graduated with honors with a degree in history. Mr. Goetz spent time at other Universities while on break from University of Florida. He took extended classes in history at Boston University, economics and history at University of Cambridge, U.K., and criminology at Florida Gulf Coast University, so that he would receive a more diverse education. After graduating from University of Florida, Mr. Goetz worked in Fort Myers for a few years before going to law school at Nova Southeastern University. While at the college of Law, Mr. Goetz worked at the Broward County State Attorney’s Office, Homicide Unit. Mr. Goetz sat second chair on numerous high profile murder cases and helped the prosecutors with their arguments and motions. Mr. Goetz successfully argued his way on Moot Court, received a book award and countless other top grades while in law school. When Mr. Goetz graduated from law school he began working with the 20th Judicial Public Defenders’ Office where he began representing juveniles with misdemeanor and felony charges. Mr. Goetz was promoted to a felony division, where he did numerous jury trials as lead attorney, from jury selection to verdict. Mr. Goetz also appeared in court on behalf of clients for arraignments, motions, violations of probation, civil injunctions, and pleas. After Mr. Goetz’s tenure at the Public Defender’s Office was over, he went to work at James L. Goetz P.A. While being employed at Goetz & Goetz, Mr. Goetz has done numerous hearings, motions, and appeals to the 2nd D.C.A. Mr. Goetz has extensive legal knowledge and is more than willing to hear your issues and resolve those issues to the best of his ability. Mr. Goetz is licensed to practice law in all Florida State Courts, District of Columbia, and the following Federal Courts: United States Supreme Court, United States Court of Appeals for the Eleventh Circuit, United States Middle District of Florida, and United States Southern District of Florida. Please do not hesitate to contact Goetz & Goetz, to settle your legal issues.';
goetz_attorney_migration_assert(
    ($gregory['bio'] ?? '') === $expected_gregory_bio,
    'The Gregory biography no longer matches the public source exactly.'
);
foreach ([
    'name'             => 'Gregory W. Goetz',
    'email'            => 'info@goetzlegal.com',
    'legacyEmail'      => 'goetzg@goetzlegal.com',
    'imageAlt'         => 'Gregory W. Goetz portrait',
    'legacyImageAlt'   => 'Gregory W. Goetz',
    'legacyEmailLabel' => 'Email Gregory W. Goetz',
] as $key => $value) {
    goetz_attorney_migration_assert(($gregory[$key] ?? null) === $value, "Gregory profile configuration changed: {$key}");
}
goetz_attorney_migration_assert(
    (int) ($gregory['imageId'] ?? 0) === $seeded_gregory_portrait_id,
    'The Gregory profile did not resolve the repository-backed portrait attachment.'
);
goetz_attorney_migration_assert(
    str_ends_with((string) ($gregory['imageUrl'] ?? ''), '/Greg-Website-Portrait-6.jpg'),
    'The Gregory profile does not use the exact tracked portrait.'
);

$gregory_content = Goetz\Site\Attorney_Profiles::canonical_content($gregory);
$gregory_blocks = array_values(array_filter(
    parse_blocks($gregory_content),
    static fn(array $block): bool => $block['blockName'] !== null
));
$gregory_card = $gregory_blocks[0]['innerBlocks'][0] ?? [];
goetz_attorney_migration_assert(
    count($gregory_blocks) === 2
        && ($gregory_blocks[0]['attrs']['className'] ?? '') === 'goetz-attorney-profile-section'
        && ($gregory_card['blockName'] ?? '') === 'goetz/attorney-card'
        && ($gregory_card['attrs']['className'] ?? '') === 'is-style-profile'
        && ($gregory_card['attrs']['bio'] ?? '') === $expected_gregory_bio,
    'The Gregory profile did not serialize into the same editable Gutenberg structure as James.'
);

/**
 * Exercise the repository's real profile definitions through the complete
 * guarded lifecycle without changing either public page.
 *
 * @param array<string, mixed> $real_profile
 */
$assert_real_profile_lifecycle = static function (
    string $slug,
    array $real_profile,
    int $expected_image_id
): void {
    $legacy = goetz_attorney_legacy_content($real_profile);
    $attachment_count_before = (int) wp_count_posts('attachment')->inherit;
    $fixture_id = wp_insert_post(wp_slash([
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => 'Attorney migration fixture ' . $slug,
        'post_name'    => 'attorney-migration-fixture-' . $slug . '-' . wp_generate_uuid4(),
        'post_content' => $legacy,
    ]), true);
    goetz_attorney_migration_assert(
        ! is_wp_error($fixture_id),
        "Could not create the exact {$slug} legacy migration fixture."
    );
    $fixture_id = (int) $fixture_id;

    try {
        $ready = Goetz\Site\Attorney_Profiles::plan_post($fixture_id, $real_profile);
        goetz_attorney_migration_assert(
            ($ready['status'] ?? '') === 'ready',
            "The exact {$slug} legacy serialization did not produce a ready plan: " . wp_json_encode($ready)
        );

        $updated = Goetz\Site\Attorney_Profiles::apply_to_post($fixture_id, $real_profile);
        goetz_attorney_migration_assert(
            ($updated['status'] ?? '') === 'updated',
            "The exact {$slug} legacy serialization was not migrated: " . wp_json_encode($updated)
        );
        goetz_attorney_migration_assert(
            get_post_field('post_content', $fixture_id, 'raw')
                === Goetz\Site\Attorney_Profiles::canonical_content($real_profile),
            "The {$slug} migration did not save the exact canonical Gutenberg content."
        );
        goetz_attorney_migration_assert(
            get_post_meta($fixture_id, Goetz\Site\Attorney_Profiles::BACKUP_META, false) === [$legacy],
            "The {$slug} migration did not preserve exactly one byte-for-byte legacy backup."
        );
        goetz_attorney_migration_assert(
            array_map(
                'strval',
                get_post_meta($fixture_id, Goetz\Site\Attorney_Profiles::VERSION_META, false)
            ) === [(string) Goetz\Site\Attorney_Profiles::VERSION],
            "The {$slug} migration did not save exactly one current version marker."
        );

        $migrated_blocks = array_values(array_filter(
            parse_blocks((string) get_post_field('post_content', $fixture_id, 'raw')),
            static fn(array $block): bool => $block['blockName'] !== null
        ));
        $migrated_card = $migrated_blocks[0]['innerBlocks'][0] ?? [];
        goetz_attorney_migration_assert(
            (int) ($migrated_card['attrs']['imageId'] ?? 0) === $expected_image_id,
            "The {$slug} migration did not reuse the verified repository portrait attachment."
        );
        goetz_attorney_migration_assert(
            (int) wp_count_posts('attachment')->inherit === $attachment_count_before,
            "The {$slug} content migration unexpectedly created another portrait attachment."
        );

        $verified = Goetz\Site\Attorney_Profiles::verify_post($fixture_id, $real_profile);
        goetz_attorney_migration_assert(
            ($verified['status'] ?? '') === 'verified',
            "The migrated {$slug} fixture failed release verification: " . wp_json_encode($verified)
        );

        update_post_meta(
            $fixture_id,
            Goetz\Site\Attorney_Profiles::BACKUP_META,
            $legacy . "\n",
            $legacy
        );
        $tampered_backup = Goetz\Site\Attorney_Profiles::verify_post($fixture_id, $real_profile);
        goetz_attorney_migration_assert(
            ($tampered_backup['status'] ?? '') === 'backup_mismatch',
            "The {$slug} verifier accepted a semantically equivalent but byte-modified backup."
        );
        update_post_meta(
            $fixture_id,
            Goetz\Site\Attorney_Profiles::BACKUP_META,
            $legacy,
            $legacy . "\n"
        );

        $noop = Goetz\Site\Attorney_Profiles::apply_to_post($fixture_id, $real_profile);
        goetz_attorney_migration_assert(
            ($noop['status'] ?? '') === 'noop',
            "The second {$slug} migration was not a no-op."
        );

        $edited = (string) get_post_field('post_content', $fixture_id, 'raw') . "\n<!-- client editor change -->";
        wp_update_post(wp_slash(['ID' => $fixture_id, 'post_content' => $edited]));
        $managed = Goetz\Site\Attorney_Profiles::plan_post($fixture_id, $real_profile);
        $managed_apply = Goetz\Site\Attorney_Profiles::apply_to_post($fixture_id, $real_profile);
        $managed_verify = Goetz\Site\Attorney_Profiles::verify_post($fixture_id, $real_profile);
        goetz_attorney_migration_assert(
            ($managed['status'] ?? '') === 'managed_modified'
                && ($managed_apply['status'] ?? '') === 'managed_modified'
                && ($managed_verify['status'] ?? '') === 'managed_modified'
                && get_post_field('post_content', $fixture_id, 'raw') === $edited,
            "The {$slug} migration did not preserve a later Gutenberg editor change."
        );
    } finally {
        wp_delete_post($fixture_id, true);
    }
};

$assert_real_profile_lifecycle('james-l-goetz', $james, $seeded_portrait_id);
$assert_real_profile_lifecycle('gregory-w-goetz', $gregory, $seeded_gregory_portrait_id);

$conflict_fixture_id = wp_insert_post(wp_slash([
    'post_type'    => 'page',
    'post_status'  => 'draft',
    'post_title'   => 'Attorney missing-media conflict fixture',
    'post_name'    => 'attorney-missing-media-conflict-' . wp_generate_uuid4(),
    'post_content' => '<!-- wp:paragraph --><p>Client-authored profile content.</p><!-- /wp:paragraph -->',
]), true);
goetz_attorney_migration_assert(
    ! is_wp_error($conflict_fixture_id),
    'Could not create the missing-media conflict fixture.'
);
$conflict_fixture_id = (int) $conflict_fixture_id;
$profile_without_media = $gregory;
$profile_without_media['imageId'] = 0;
$profile_without_media['imageUrl'] = '';
$attachment_count_before_conflict = (int) wp_count_posts('attachment')->inherit;

try {
    $conflict_plan = Goetz\Site\Attorney_Profiles::plan_post($conflict_fixture_id, $profile_without_media);
    goetz_attorney_migration_assert(
        ($conflict_plan['status'] ?? '') === 'conflict',
        'Missing media masked client-edited attorney content instead of failing closed as a conflict.'
    );
    goetz_attorney_migration_assert(
        (int) wp_count_posts('attachment')->inherit === $attachment_count_before_conflict
            && get_post_meta($conflict_fixture_id, Goetz\Site\Attorney_Profiles::BACKUP_META, false) === []
            && get_post_meta($conflict_fixture_id, Goetz\Site\Attorney_Profiles::VERSION_META, false) === [],
        'Planning a client-edited profile with missing media performed a write.'
    );

    wp_update_post(wp_slash([
        'ID'           => $conflict_fixture_id,
        'post_content' => goetz_attorney_legacy_content($profile_without_media),
    ]));
    $missing_media_plan = Goetz\Site\Attorney_Profiles::plan_post(
        $conflict_fixture_id,
        $profile_without_media
    );
    goetz_attorney_migration_assert(
        ($missing_media_plan['status'] ?? '') === 'missing_image',
        'An exact legacy profile without its portrait did not request the guarded media seed.'
    );
} finally {
    wp_delete_post($conflict_fixture_id, true);
}

$profile = [
    'name'             => 'Integration Attorney',
    'bio'              => 'A corrected public biography.',
    'legacyBio'        => 'A complete integration biography.',
    'email'            => 'integration@example.test',
    'legacyEmail'      => 'integration@example.test',
    'imageId'          => 321,
    'imageUrl'         => 'https://example.test/integration-attorney.jpg',
    'imageAlt'         => 'Integration Attorney portrait',
    'legacyImageUrl'   => 'https://legacy.example/integration-attorney.jpg',
    'legacyImageAlt'   => 'Integration Attorney portrait',
    'legacyEmailLabel' => 'Email Integration Attorney',
];

$canonical_card = [
    'blockName'    => 'goetz/attorney-card',
    'attrs'        => [
        'name'      => 'Integration Attorney',
        'bio'       => 'A corrected public biography.',
        'email'     => 'integration@example.test',
        'imageId'   => 321,
        'imageUrl'  => 'https://example.test/integration-attorney.jpg',
        'imageAlt'  => 'Integration Attorney portrait',
        'className' => 'is-style-profile',
    ],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
];
$canonical_blocks = [
    [
        'blockName'    => 'core/group',
        'attrs'        => [
            'className' => 'goetz-attorney-profile-section',
            'layout'    => ['type' => 'default'],
        ],
        'innerBlocks'  => [$canonical_card],
        'innerHTML'    => '<div class="wp-block-group goetz-attorney-profile-section"></div>',
        'innerContent' => [
            '<div class="wp-block-group goetz-attorney-profile-section">',
            null,
            '</div>',
        ],
    ],
    [
        'blockName'    => 'goetz/cta',
        'attrs'        => [],
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ],
];
$canonical_content = Goetz\Site\Attorney_Profiles::canonical_content($profile);
goetz_attorney_migration_assert(
    $canonical_content === serialize_blocks($canonical_blocks),
    'Canonical attorney content did not use the expected WordPress block serialization.'
);
goetz_attorney_migration_assert(
    serialize_blocks(parse_blocks($canonical_content)) === $canonical_content,
    'Canonical attorney content did not survive an exact WordPress parse/serialize round trip.'
);

$legacy_content = '<!-- wp:group {"className":"goetz-section","layout":{"type":"constrained"}} -->'
    . '<div class="wp-block-group goetz-section">'
    . '<!-- wp:goetz/attorney-card {"name":"Integration Attorney","email":"integration@example.test","imageUrl":"https://legacy.example/integration-attorney.jpg","imageAlt":"Integration Attorney portrait"} /-->'
    . '<!-- wp:paragraph --><p>A complete integration biography.</p><!-- /wp:paragraph -->'
    . '<!-- wp:paragraph --><p>Email Integration Attorney</p><!-- /wp:paragraph -->'
    . '</div><!-- /wp:group -->'
    . '<!-- wp:goetz/cta /-->';

$post_id = wp_insert_post([
    'post_type'    => 'page',
    'post_status'  => 'draft',
    'post_title'   => 'Integration Attorney',
    'post_name'    => 'goetz-attorney-profile-integration',
    'post_content' => $legacy_content,
], true);

goetz_attorney_migration_assert(! is_wp_error($post_id), 'Could not create the temporary attorney migration page.');
$post_id = (int) $post_id;

update_post_meta($post_id, '_wp_page_template', 'page-templates/template-contact.php');
update_post_meta($post_id, '_yoast_wpseo_title', 'Preserved SEO title');

try {
    $reset_migration_fixture = static function () use ($post_id, $legacy_content): void {
        wp_update_post(wp_slash([
            'ID'           => $post_id,
            'post_title'   => 'Integration Attorney',
            'post_status'  => 'draft',
            'post_content' => $legacy_content,
        ]));
        delete_post_meta($post_id, Goetz\Site\Attorney_Profiles::BACKUP_META);
        delete_post_meta($post_id, Goetz\Site\Attorney_Profiles::VERSION_META);
    };
    $is_target_content_update_query = static function (string $query) use ($post_id): bool {
        global $wpdb;

        $normalized = str_replace('`', '', $query);
        return preg_match(
            '/^\s*UPDATE\s+' . preg_quote($wpdb->posts, '/') . '\s+SET\s+/i',
            $normalized
        ) === 1
            && stripos($normalized, 'post_content') !== false
            && preg_match('/\bID\s*=\s*[\'\"]?' . $post_id . '[\'\"]?/i', $normalized) === 1;
    };

    $legacy_customizations = [
        'group class' => str_replace(
            '"className":"goetz-section"',
            '"className":"editor-customized-section"',
            $legacy_content
        ),
        'card email' => str_replace(
            '"email":"integration@example.test"',
            '"email":"editor@example.test"',
            $legacy_content
        ),
        'card image' => str_replace(
            'https://legacy.example/integration-attorney.jpg',
            'https://legacy.example/editor-selected.jpg',
            $legacy_content
        ),
        'card image alt' => str_replace(
            '"imageAlt":"Integration Attorney portrait"',
            '"imageAlt":"Editor supplied portrait description"',
            $legacy_content
        ),
        'card role' => str_replace(
            '"imageAlt":"Integration Attorney portrait"',
            '"imageAlt":"Integration Attorney portrait","role":"Managing Partner"',
            $legacy_content
        ),
        'card profile URL' => str_replace(
            '"imageAlt":"Integration Attorney portrait"',
            '"imageAlt":"Integration Attorney portrait","profileUrl":"https://example.test/editor-profile"',
            $legacy_content
        ),
        'biography formatting' => str_replace(
            '<p>A complete integration biography.</p>',
            '<p>A <strong>complete</strong> integration biography.</p>',
            $legacy_content
        ),
        'email-label link' => str_replace(
            '<p>Email Integration Attorney</p>',
            '<p><a href="mailto:integration@example.test">Email Integration Attorney</a></p>',
            $legacy_content
        ),
        'CTA attributes' => str_replace(
            '<!-- wp:goetz/cta /-->',
            '<!-- wp:goetz/cta {"heading":"Editor customized CTA"} /-->',
            $legacy_content
        ),
        'trailing classic HTML' => $legacy_content . '<p>Client-authored profile note.</p>',
        'serialization whitespace' => $legacy_content . "\n",
    ];

    foreach ($legacy_customizations as $label => $customized_content) {
        wp_update_post(['ID' => $post_id, 'post_content' => $customized_content]);
        $guarded_result = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
        goetz_attorney_migration_assert(
            ($guarded_result['status'] ?? '') === 'conflict',
            "Migration did not fail closed after an editor changed the legacy {$label}."
        );
        goetz_attorney_migration_assert(
            get_post($post_id)->post_content === $customized_content,
            "Migration overwrote the editor-customized legacy {$label}."
        );
    }

    $reset_migration_fixture();
    wp_update_post(wp_slash(['ID' => $post_id, 'post_content' => $canonical_content]));
    $unmanaged_canonical_plan = Goetz\Site\Attorney_Profiles::plan_post($post_id, $profile);
    $unmanaged_canonical_apply = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
    goetz_attorney_migration_assert(
        ($unmanaged_canonical_plan['status'] ?? '') === 'migration_evidence_mismatch'
            && ($unmanaged_canonical_apply['status'] ?? '') === 'error'
            && get_post_field('post_content', $post_id, 'raw') === $canonical_content
            && ! metadata_exists('post', $post_id, Goetz\Site\Attorney_Profiles::BACKUP_META)
            && ! metadata_exists('post', $post_id, Goetz\Site\Attorney_Profiles::VERSION_META),
        'Canonical content without exact migration evidence was accepted or mutated.'
    );

    $reset_migration_fixture();
    add_post_meta($post_id, Goetz\Site\Attorney_Profiles::VERSION_META, '0', true);
    $stale_version_plan = Goetz\Site\Attorney_Profiles::plan_post($post_id, $profile);
    $stale_version_apply = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
    goetz_attorney_migration_assert(
        ($stale_version_plan['status'] ?? '') === 'version_conflict'
            && ($stale_version_apply['status'] ?? '') === 'error'
            && get_post_field('post_content', $post_id, 'raw') === $legacy_content
            && get_post_meta($post_id, Goetz\Site\Attorney_Profiles::VERSION_META, false) === ['0']
            && ! metadata_exists('post', $post_id, Goetz\Site\Attorney_Profiles::BACKUP_META),
        'A stale migration marker was overwritten or allowed partial attorney-profile state.'
    );

    $reset_migration_fixture();
    $fake_backup_success = static function ($check, $object_id, $meta_key) use ($post_id) {
        if ((int) $object_id === $post_id && $meta_key === Goetz\Site\Attorney_Profiles::BACKUP_META) {
            return PHP_INT_MAX;
        }

        return $check;
    };
    add_filter('add_post_metadata', $fake_backup_success, 999, 3);
    try {
        $backup_failure = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
    } finally {
        remove_filter('add_post_metadata', $fake_backup_success, 999);
    }
    goetz_attorney_migration_assert(
        ($backup_failure['status'] ?? '') === 'error'
            && str_contains((string) ($backup_failure['error'] ?? ''), 'backup'),
        'Migration did not report an injected backup-write failure.'
    );
    goetz_attorney_migration_assert(
        get_post_field('post_content', $post_id, 'raw') === $legacy_content
            && ! metadata_exists('post', $post_id, Goetz\Site\Attorney_Profiles::BACKUP_META)
            && ! metadata_exists('post', $post_id, Goetz\Site\Attorney_Profiles::VERSION_META),
        'A failed backup write changed content or left migration metadata.'
    );

    $reset_migration_fixture();
    $decoy_post_id = wp_insert_post([
        'post_type'    => 'page',
        'post_status'  => 'draft',
        'post_title'   => 'Backup ownership decoy',
        'post_content' => 'This page must not be touched by the attorney migration.',
    ], true);
    goetz_attorney_migration_assert(! is_wp_error($decoy_post_id), 'Could not create the backup ownership decoy.');
    $decoy_post_id = (int) $decoy_post_id;
    $decoy_meta_id = add_post_meta(
        $decoy_post_id,
        Goetz\Site\Attorney_Profiles::BACKUP_META,
        $legacy_content,
        true
    );
    goetz_attorney_migration_assert(
        is_int($decoy_meta_id) && $decoy_meta_id > 0,
        'Could not create the deceptive cross-post backup row.'
    );

    $return_cross_post_meta_id = static function ($check, $object_id, $meta_key) use (
        $post_id,
        $decoy_meta_id
    ) {
        if ((int) $object_id === $post_id && $meta_key === Goetz\Site\Attorney_Profiles::BACKUP_META) {
            return $decoy_meta_id;
        }

        return $check;
    };
    add_filter('add_post_metadata', $return_cross_post_meta_id, 999, 3);
    try {
        $cross_post_backup = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
    } finally {
        remove_filter('add_post_metadata', $return_cross_post_meta_id, 999);
    }

    try {
        goetz_attorney_migration_assert(
            ($cross_post_backup['status'] ?? '') === 'error'
                && str_contains((string) ($cross_post_backup['error'] ?? ''), 'backup'),
            'Migration trusted a matching backup row owned by a different post.'
        );
        goetz_attorney_migration_assert(
            get_post_field('post_content', $post_id, 'raw') === $legacy_content
                && ! metadata_exists('post', $post_id, Goetz\Site\Attorney_Profiles::BACKUP_META)
                && ! metadata_exists('post', $post_id, Goetz\Site\Attorney_Profiles::VERSION_META),
            'A deceptive cross-post backup allowed the target page to change.'
        );
        $decoy_backup = get_metadata_by_mid('post', (int) $decoy_meta_id);
        goetz_attorney_migration_assert(
            is_object($decoy_backup)
                && (int) ($decoy_backup->post_id ?? 0) === $decoy_post_id
                && ($decoy_backup->meta_key ?? null) === Goetz\Site\Attorney_Profiles::BACKUP_META
                && ($decoy_backup->meta_value ?? null) === $legacy_content,
            'Migration deleted or altered a deceptive backup row owned by another post.'
        );
    } finally {
        wp_delete_post($decoy_post_id, true);
    }

    $reset_migration_fixture();
    add_post_meta($post_id, Goetz\Site\Attorney_Profiles::BACKUP_META, 'Foreign backup content', true);
    $conflicting_backup = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
    goetz_attorney_migration_assert(
        ($conflicting_backup['status'] ?? '') === 'error'
            && str_contains((string) ($conflicting_backup['error'] ?? ''), 'backup'),
        'Migration trusted a pre-existing backup that did not match the exact original content.'
    );
    goetz_attorney_migration_assert(
        get_post_field('post_content', $post_id, 'raw') === $legacy_content
            && get_post_meta($post_id, Goetz\Site\Attorney_Profiles::BACKUP_META, true) === 'Foreign backup content'
            && ! metadata_exists('post', $post_id, Goetz\Site\Attorney_Profiles::VERSION_META),
        'A conflicting backup was overwritten or allowed the page migration to continue.'
    );

    $reset_migration_fixture();
    $concurrent_content = $legacy_content
        . '<!-- wp:paragraph --><p>Concurrent editor content survives.</p><!-- /wp:paragraph -->';
    $inject_concurrent_change = static function ($meta_id, $object_id, $meta_key) use (
        $post_id,
        $concurrent_content
    ): void {
        if ((int) $object_id !== $post_id || $meta_key !== Goetz\Site\Attorney_Profiles::BACKUP_META) {
            return;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            ['post_content' => $concurrent_content],
            ['ID' => $post_id],
            ['%s'],
            ['%d']
        );
        clean_post_cache($post_id);
    };
    add_action('added_post_meta', $inject_concurrent_change, 10, 3);
    try {
        $concurrent_result = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
    } finally {
        remove_action('added_post_meta', $inject_concurrent_change, 10);
    }
    goetz_attorney_migration_assert(
        ($concurrent_result['status'] ?? '') === 'conflict',
        'Migration did not fail closed when the page fingerprint changed immediately before update.'
    );
    goetz_attorney_migration_assert(
        get_post_field('post_content', $post_id, 'raw') === $concurrent_content
            && ! metadata_exists('post', $post_id, Goetz\Site\Attorney_Profiles::BACKUP_META)
            && ! metadata_exists('post', $post_id, Goetz\Site\Attorney_Profiles::VERSION_META),
        'Concurrent editor content was overwritten or the failed migration left owned metadata.'
    );

    $reset_migration_fixture();
    $cas_content = $legacy_content
        . '<!-- wp:paragraph --><p>Exact compare-and-swap editor content survives.</p><!-- /wp:paragraph -->';
    $cas_content_injected = false;
    $inject_at_content_update = static function (string $query) use (
        &$cas_content_injected,
        $is_target_content_update_query,
        $post_id,
        $cas_content
    ): string {
        if ($cas_content_injected || ! $is_target_content_update_query($query)) {
            return $query;
        }

        $cas_content_injected = true;
        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            ['post_content' => $cas_content],
            ['ID' => $post_id],
            ['%s'],
            ['%d']
        );
        clean_post_cache($post_id);

        return $query;
    };
    add_filter('query', $inject_at_content_update, PHP_INT_MAX);
    try {
        $cas_conflict = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
    } finally {
        remove_filter('query', $inject_at_content_update, PHP_INT_MAX);
    }
    goetz_attorney_migration_assert(
        $cas_content_injected,
        'The migration did not attempt its content write through the guarded database update path.'
    );
    goetz_attorney_migration_assert(
        ($cas_conflict['status'] ?? '') === 'conflict',
        'Migration did not report a conflict when content changed in the exact database update path.'
    );
    goetz_attorney_migration_assert(
        get_post_field('post_content', $post_id, 'raw') === $cas_content
            && ! metadata_exists('post', $post_id, Goetz\Site\Attorney_Profiles::BACKUP_META)
            && ! metadata_exists('post', $post_id, Goetz\Site\Attorney_Profiles::VERSION_META),
        'The database update race overwrote editor content or left migration metadata.'
    );

    $reset_migration_fixture();
    $concurrent_title = 'Concurrent editor title survives';
    $title_injected = false;
    $inject_omitted_field_at_content_update = static function (string $query) use (
        &$title_injected,
        $is_target_content_update_query,
        $post_id,
        $concurrent_title
    ): string {
        if ($title_injected || ! $is_target_content_update_query($query)) {
            return $query;
        }

        $title_injected = true;
        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            ['post_title' => $concurrent_title],
            ['ID' => $post_id],
            ['%s'],
            ['%d']
        );
        clean_post_cache($post_id);

        return $query;
    };
    add_filter('query', $inject_omitted_field_at_content_update, PHP_INT_MAX);
    try {
        $omitted_field_result = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
    } finally {
        remove_filter('query', $inject_omitted_field_at_content_update, PHP_INT_MAX);
    }
    goetz_attorney_migration_assert(
        $title_injected && ($omitted_field_result['status'] ?? '') === 'updated',
        'Migration did not complete after an editor changed a field outside the content CAS.'
    );
    goetz_attorney_migration_assert(
        get_post_field('post_title', $post_id, 'raw') === $concurrent_title
            && get_post_field('post_content', $post_id, 'raw') === $canonical_content,
        'Migration overwrote an editor mutation to a field omitted from its content update.'
    );

    $reset_migration_fixture();
    $cleanup_conflict_content = $legacy_content
        . '<!-- wp:paragraph --><p>Cleanup failure content survives.</p><!-- /wp:paragraph -->';
    $cleanup_conflict_injected = false;
    $inject_cleanup_conflict = static function (string $query) use (
        &$cleanup_conflict_injected,
        $is_target_content_update_query,
        $post_id,
        $cleanup_conflict_content
    ): string {
        if ($cleanup_conflict_injected || ! $is_target_content_update_query($query)) {
            return $query;
        }

        $cleanup_conflict_injected = true;
        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            ['post_content' => $cleanup_conflict_content],
            ['ID' => $post_id],
            ['%s'],
            ['%d']
        );
        clean_post_cache($post_id);

        return $query;
    };
    $block_owned_backup_cleanup = static function ($check, $meta_id) use ($post_id) {
        $meta = get_metadata_by_mid('post', (int) $meta_id);
        if (is_object($meta)
            && (int) ($meta->post_id ?? 0) === $post_id
            && ($meta->meta_key ?? null) === Goetz\Site\Attorney_Profiles::BACKUP_META) {
            return false;
        }

        return $check;
    };
    add_filter('query', $inject_cleanup_conflict, PHP_INT_MAX);
    add_filter('delete_post_metadata_by_mid', $block_owned_backup_cleanup, 999, 2);
    try {
        $cleanup_failure = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
    } finally {
        remove_filter('query', $inject_cleanup_conflict, PHP_INT_MAX);
        remove_filter('delete_post_metadata_by_mid', $block_owned_backup_cleanup, 999);
    }
    goetz_attorney_migration_assert(
        ($cleanup_failure['status'] ?? '') === 'error'
            && str_contains(strtolower((string) ($cleanup_failure['error'] ?? '')), 'clean'),
        'Migration did not surface an owned-backup cleanup failure.'
    );
    goetz_attorney_migration_assert(
        get_post_field('post_content', $post_id, 'raw') === $cleanup_conflict_content
            && get_post_meta($post_id, Goetz\Site\Attorney_Profiles::BACKUP_META, true) === $legacy_content
            && ! metadata_exists('post', $post_id, Goetz\Site\Attorney_Profiles::VERSION_META),
        'An owned-backup cleanup failure changed concurrent content or hid the retained recovery row.'
    );

    $reset_migration_fixture();
    $database_error_injected = false;
    $inject_content_database_error = static function (string $query) use (
        &$database_error_injected,
        $is_target_content_update_query
    ): string {
        if ($database_error_injected || ! $is_target_content_update_query($query)) {
            return $query;
        }

        $database_error_injected = true;
        return 'UPDATE goetz_missing_attorney_posts SET post_content = NULL';
    };
    global $wpdb;
    $suppressed_errors_before = $wpdb->suppress_errors(true);
    add_filter('query', $inject_content_database_error, PHP_INT_MAX);
    try {
        $database_error = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
    } finally {
        remove_filter('query', $inject_content_database_error, PHP_INT_MAX);
        $wpdb->suppress_errors($suppressed_errors_before);
    }
    goetz_attorney_migration_assert(
        $database_error_injected
            && ($database_error['status'] ?? '') === 'error'
            && ($database_error['status'] ?? '') !== 'conflict',
        'Migration did not distinguish a content database error from a compare-and-swap conflict.'
    );
    goetz_attorney_migration_assert(
        get_post_field('post_content', $post_id, 'raw') === $legacy_content
            && ! metadata_exists('post', $post_id, Goetz\Site\Attorney_Profiles::BACKUP_META)
            && ! metadata_exists('post', $post_id, Goetz\Site\Attorney_Profiles::VERSION_META),
        'A content database error changed the page or left owned migration metadata.'
    );

    $reset_migration_fixture();
    $fail_version = static function ($check, $object_id, $meta_key) use ($post_id) {
        if ((int) $object_id === $post_id && $meta_key === Goetz\Site\Attorney_Profiles::VERSION_META) {
            return true;
        }

        return $check;
    };
    add_filter('update_post_metadata', $fail_version, 999, 3);
    try {
        $version_failure = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
    } finally {
        remove_filter('update_post_metadata', $fail_version, 999);
    }
    goetz_attorney_migration_assert(
        ($version_failure['status'] ?? '') === 'error'
            && str_contains((string) ($version_failure['error'] ?? ''), 'version'),
        'Migration did not report an injected version-marker verification failure.'
    );
    goetz_attorney_migration_assert(
        get_post_field('post_content', $post_id, 'raw') === $legacy_content
            && ! metadata_exists('post', $post_id, Goetz\Site\Attorney_Profiles::BACKUP_META)
            && ! metadata_exists('post', $post_id, Goetz\Site\Attorney_Profiles::VERSION_META),
        'A failed version write did not roll content and owned metadata back to the original state.'
    );

    $reset_migration_fixture();
    $concurrent_version_content = $canonical_content
        . '<!-- wp:paragraph --><p>Concurrent version-failure editor content survives.</p><!-- /wp:paragraph -->';
    $concurrent_version = 77;
    $concurrent_version_injected = false;
    $inject_concurrent_version_failure = static function ($check, $object_id, $meta_key) use (
        &$concurrent_version_injected,
        $post_id,
        $concurrent_version_content,
        $concurrent_version
    ) {
        if ($concurrent_version_injected
            || (int) $object_id !== $post_id
            || $meta_key !== Goetz\Site\Attorney_Profiles::VERSION_META) {
            return $check;
        }

        $concurrent_version_injected = true;
        global $wpdb;
        $wpdb->update(
            $wpdb->posts,
            ['post_content' => $concurrent_version_content],
            ['ID' => $post_id],
            ['%s'],
            ['%d']
        );
        $wpdb->insert(
            $wpdb->postmeta,
            [
                'post_id'    => $post_id,
                'meta_key'   => Goetz\Site\Attorney_Profiles::VERSION_META,
                'meta_value' => (string) $concurrent_version,
            ],
            ['%d', '%s', '%s']
        );
        clean_post_cache($post_id);
        wp_cache_delete($post_id, 'post_meta');

        return true;
    };
    add_filter('update_post_metadata', $inject_concurrent_version_failure, 999, 3);
    try {
        $concurrent_version_failure = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
    } finally {
        remove_filter('update_post_metadata', $inject_concurrent_version_failure, 999);
    }
    goetz_attorney_migration_assert(
        $concurrent_version_injected
            && ($concurrent_version_failure['status'] ?? '') === 'error'
            && str_contains((string) ($concurrent_version_failure['error'] ?? ''), 'version'),
        'Migration did not surface the combined concurrent mutation and version-write failure.'
    );
    goetz_attorney_migration_assert(
        get_post_field('post_content', $post_id, 'raw') === $concurrent_version_content
            && (int) get_post_meta($post_id, Goetz\Site\Attorney_Profiles::VERSION_META, true) === $concurrent_version
            && get_post_meta($post_id, Goetz\Site\Attorney_Profiles::BACKUP_META, true) === $legacy_content,
        'Version-failure rollback overwrote concurrent content/version state or removed its recovery backup.'
    );

    $reset_migration_fixture();
    $concurrent_marker_only = 88;
    $concurrent_marker_injected = false;
    $inject_concurrent_marker_only = static function ($check, $object_id, $meta_key) use (
        &$concurrent_marker_injected,
        $post_id,
        $concurrent_marker_only
    ) {
        if ($concurrent_marker_injected
            || (int) $object_id !== $post_id
            || $meta_key !== Goetz\Site\Attorney_Profiles::VERSION_META) {
            return $check;
        }

        $concurrent_marker_injected = true;
        global $wpdb;
        $wpdb->insert(
            $wpdb->postmeta,
            [
                'post_id'    => $post_id,
                'meta_key'   => Goetz\Site\Attorney_Profiles::VERSION_META,
                'meta_value' => (string) $concurrent_marker_only,
            ],
            ['%d', '%s', '%s']
        );
        wp_cache_delete($post_id, 'post_meta');

        return true;
    };
    add_filter('update_post_metadata', $inject_concurrent_marker_only, 999, 3);
    try {
        $concurrent_marker_failure = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
    } finally {
        remove_filter('update_post_metadata', $inject_concurrent_marker_only, 999);
    }
    goetz_attorney_migration_assert(
        $concurrent_marker_injected && ($concurrent_marker_failure['status'] ?? '') === 'error',
        'Migration did not surface a concurrent version-marker write.'
    );
    goetz_attorney_migration_assert(
        get_post_field('post_content', $post_id, 'raw') === $canonical_content
            && (int) get_post_meta($post_id, Goetz\Site\Attorney_Profiles::VERSION_META, true) === $concurrent_marker_only
            && get_post_meta($post_id, Goetz\Site\Attorney_Profiles::BACKUP_META, true) === $legacy_content,
        'Rollback restored content across a concurrent version marker it did not own.'
    );

    $reset_migration_fixture();
    $same_version_marker_injected = false;
    $inject_same_version_marker = static function ($check, $object_id, $meta_key) use (
        &$same_version_marker_injected,
        $post_id
    ) {
        if ($same_version_marker_injected
            || (int) $object_id !== $post_id
            || $meta_key !== Goetz\Site\Attorney_Profiles::VERSION_META) {
            return $check;
        }

        $same_version_marker_injected = true;
        global $wpdb;
        $wpdb->insert(
            $wpdb->postmeta,
            [
                'post_id'    => $post_id,
                'meta_key'   => Goetz\Site\Attorney_Profiles::VERSION_META,
                'meta_value' => (string) Goetz\Site\Attorney_Profiles::VERSION,
            ],
            ['%d', '%s', '%s']
        );
        wp_cache_delete($post_id, 'post_meta');

        return true;
    };
    add_filter('update_post_metadata', $inject_same_version_marker, 999, 3);
    try {
        $same_version_marker_failure = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
    } finally {
        remove_filter('update_post_metadata', $inject_same_version_marker, 999);
    }
    goetz_attorney_migration_assert(
        $same_version_marker_injected && ($same_version_marker_failure['status'] ?? '') === 'error',
        'Migration accepted a same-version marker without owning its exact metadata row.'
    );
    goetz_attorney_migration_assert(
        get_post_field('post_content', $post_id, 'raw') === $canonical_content
            && (int) get_post_meta($post_id, Goetz\Site\Attorney_Profiles::VERSION_META, true)
                === Goetz\Site\Attorney_Profiles::VERSION
            && get_post_meta($post_id, Goetz\Site\Attorney_Profiles::BACKUP_META, true) === $legacy_content,
        'Same-version ownership failure overwrote concurrent state or removed the recovery backup.'
    );

    $reset_migration_fixture();
    $result = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
    goetz_attorney_migration_assert(
        ($result['status'] ?? '') === 'updated',
        'The known legacy profile was not migrated: ' . wp_json_encode($result)
    );

    $post = get_post($post_id);
    goetz_attorney_migration_assert($post instanceof WP_Post, 'The migrated temporary page disappeared.');
    goetz_attorney_migration_assert($post->post_title === 'Integration Attorney', 'Migration changed the page title.');
    goetz_attorney_migration_assert($post->post_status === 'draft', 'Migration changed the page status.');
    goetz_attorney_migration_assert(get_post_meta($post_id, '_wp_page_template', true) === 'page-templates/template-contact.php', 'Migration changed the page template.');
    goetz_attorney_migration_assert(get_post_meta($post_id, '_yoast_wpseo_title', true) === 'Preserved SEO title', 'Migration changed Yoast metadata.');
    goetz_attorney_migration_assert(
        get_post_meta($post_id, Goetz\Site\Attorney_Profiles::BACKUP_META, true) === $legacy_content,
        'Migration did not preserve the exact original page content.'
    );

    $blocks = array_values(array_filter(parse_blocks($post->post_content), static fn(array $block): bool => $block['blockName'] !== null));
    goetz_attorney_migration_assert(count($blocks) === 2, 'Migrated content must contain one profile group and one CTA.');
    goetz_attorney_migration_assert($blocks[0]['blockName'] === 'core/group' && $blocks[1]['blockName'] === 'goetz/cta', 'Migrated top-level block order changed.');
    goetz_attorney_migration_assert(($blocks[0]['attrs']['className'] ?? '') === 'goetz-attorney-profile-section', 'Migrated group lost its profile class.');

    $card = $blocks[0]['innerBlocks'][0] ?? [];
    goetz_attorney_migration_assert(($card['blockName'] ?? '') === 'goetz/attorney-card', 'Migrated page does not reuse the attorney card.');
    foreach ([
        'name'      => 'Integration Attorney',
        'bio'       => 'A corrected public biography.',
        'email'     => 'integration@example.test',
        'imageId'   => 321,
        'imageUrl'  => 'https://example.test/integration-attorney.jpg',
        'imageAlt'  => 'Integration Attorney portrait',
        'className' => 'is-style-profile',
    ] as $key => $value) {
        goetz_attorney_migration_assert(($card['attrs'][$key] ?? null) === $value, "Migrated profile attribute changed: {$key}");
    }
    goetz_attorney_migration_assert(count($blocks[0]['innerBlocks']) === 1, 'Redundant legacy biography/email blocks remain.');

    $first_content = $post->post_content;
    $second = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
    goetz_attorney_migration_assert(($second['status'] ?? '') === 'noop', 'Second profile migration was not a byte-for-byte no-op.');
    goetz_attorney_migration_assert(get_post($post_id)->post_content === $first_content, 'Second profile migration changed saved content.');

    wp_update_post(['ID' => $post_id, 'post_content' => $first_content . "\n<!-- editor change -->"]);
    $edited_content = get_post($post_id)->post_content;
    $third = Goetz\Site\Attorney_Profiles::apply_to_post($post_id, $profile);
    goetz_attorney_migration_assert(($third['status'] ?? '') === 'managed_modified', 'Migration did not protect later editor changes.');
    goetz_attorney_migration_assert(get_post($post_id)->post_content === $edited_content, 'Migration overwrote later editor changes.');
} finally {
    wp_delete_post($post_id, true);
}

fwrite(STDOUT, "Attorney profile migration checks passed.\n");
