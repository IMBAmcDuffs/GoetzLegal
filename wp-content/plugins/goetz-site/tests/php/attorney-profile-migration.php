<?php

if (! defined('ABSPATH')) {
    fwrite(STDERR, "attorney-profile-migration.php must run through WP-CLI.\n");
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
            && (int) ($checksum_guarded_profile['imageId'] ?? 0) === $seeded_portrait_id,
        'Profile resolution trusted a seed-key attachment whose bytes do not match the tracked portrait.'
    );
} finally {
    wp_delete_attachment($tampered_portrait_id, true);
}

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

    wp_update_post(['ID' => $post_id, 'post_content' => $legacy_content]);
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
