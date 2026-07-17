<?php

if (! defined('ABSPATH')) {
    fwrite(STDERR, "attorney-profile.php must run through WP-CLI.\n");
    exit(1);
}

/**
 * @param bool $condition
 */
function goetz_attorney_profile_assert($condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$profile = render_block([
    'blockName'    => 'goetz/attorney-card',
    'attrs'        => [
        'className' => 'is-style-profile',
        'name'      => 'James L. Goetz',
        'bio'       => 'A complete editable attorney biography.',
        'email'     => 'info@example.test',
        'imageUrl'  => 'https://example.test/james.jpg',
        'imageAlt'  => 'James L. Goetz portrait',
    ],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]);

goetz_attorney_profile_assert(substr_count($profile, '<h2') === 1, 'Profile must render exactly one H2 beneath the page H1.');
goetz_attorney_profile_assert(substr_count($profile, '<article') === 1, 'Profile must remain a single semantic attorney article.');

foreach ([
    'goetz-attorney-card--profile',
    'goetz-attorney-card__mark',
    'law-scale-icon-purple.png',
    'alt=""',
    'aria-hidden="true"',
    '<span class="goetz-attorney-card__accent">James L.</span> Goetz',
    '<p>A complete editable attorney biography.</p>',
    'href="mailto:info@example.test"',
    '>Email James L. Goetz</a>',
] as $needle) {
    goetz_attorney_profile_assert(str_contains($profile, $needle), "Profile output changed: {$needle}");
}

goetz_attorney_profile_assert(! str_contains($profile, 'Read Full Bio'), 'Profile must not link to itself.');
goetz_attorney_profile_assert(! str_contains($profile, 'https://goetzlegal.com'), 'Profile embeds the legacy source origin.');

$card = render_block([
    'blockName'    => 'goetz/attorney-card',
    'attrs'        => [
        'name'     => 'Homepage Attorney',
        'bio'      => 'Short homepage biography.',
        'email'    => 'home@example.test',
        'imageUrl' => 'https://example.test/home.jpg',
    ],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
]);

goetz_attorney_profile_assert(! str_contains($card, 'goetz-attorney-card--profile'), 'Default card unexpectedly uses profile layout.');
goetz_attorney_profile_assert(! str_contains($card, 'goetz-attorney-card__mark'), 'Default card unexpectedly renders the profile mark.');
goetz_attorney_profile_assert(str_contains($card, '<h2>Homepage Attorney</h2>'), 'Standalone default card H2 markup changed.');

fwrite(STDOUT, "Attorney profile integration checks passed.\n");
