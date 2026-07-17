<?php

if (! defined('ABSPATH')) {
    fwrite(STDERR, "attorney-profile-pattern.php must run through WP-CLI.\n");
    exit(1);
}

/**
 * @param bool $condition
 */
function goetz_attorney_pattern_assert($condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

goetz_attorney_pattern_assert(
    class_exists('Goetz\\Site\\Attorney_Profiles'),
    'The reusable attorney-profile pattern owner is missing.'
);

Goetz\Site\Attorney_Profiles::register_patterns();

$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered('goetz/attorney-profile-page');
goetz_attorney_pattern_assert(is_array($pattern), 'The attorney-profile page pattern is not registered.');
goetz_attorney_pattern_assert(($pattern['inserter'] ?? false) === true, 'The attorney-profile pattern is not editor-insertable.');
goetz_attorney_pattern_assert(($pattern['postTypes'] ?? []) === ['page'], 'The attorney-profile pattern is not scoped to pages.');
goetz_attorney_pattern_assert(! isset($pattern['blockTypes']), 'The profile pattern must not interrupt every new page as a starter pattern.');

$blocks = parse_blocks((string) ($pattern['content'] ?? ''));
$blocks = array_values(array_filter($blocks, static fn(array $block): bool => $block['blockName'] !== null));
goetz_attorney_pattern_assert(count($blocks) === 2, 'The attorney-profile pattern must contain one profile group and one CTA.');
goetz_attorney_pattern_assert($blocks[0]['blockName'] === 'core/group', 'The attorney-profile pattern must begin with a core group.');
goetz_attorney_pattern_assert(
    ($blocks[0]['attrs']['className'] ?? '') === 'goetz-attorney-profile-section',
    'The profile group lost its layout scope.'
);
goetz_attorney_pattern_assert(count($blocks[0]['innerBlocks']) === 1, 'The profile group must contain exactly one editable attorney card.');
goetz_attorney_pattern_assert($blocks[0]['innerBlocks'][0]['blockName'] === 'goetz/attorney-card', 'The profile pattern does not reuse the stable attorney card.');
goetz_attorney_pattern_assert(
    ($blocks[0]['innerBlocks'][0]['attrs']['className'] ?? '') === 'is-style-profile',
    'The pattern does not select the attorney profile style.'
);
goetz_attorney_pattern_assert($blocks[1]['blockName'] === 'goetz/cta', 'The attorney-profile pattern must end with the reusable CTA.');

fwrite(STDOUT, "Attorney profile pattern checks passed.\n");
