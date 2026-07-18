<?php

declare(strict_types=1);

namespace Goetz\Site\Editor;

use WP_Block_Editor_Context;
use WP_Post;

final class Homepage_Editor
{
    public static function hooks(): void
    {
        add_filter('block_editor_settings_all', [self::class, 'filter_settings'], 10, 2);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function filter_settings(array $settings, WP_Block_Editor_Context $context): array
    {
        $post = $context->post ?? null;
        $front_page_id = (int) get_option('page_on_front');
        if (get_option('show_on_front') !== 'page'
            || ($context->name ?? '') !== 'core/edit-post'
            || ! $post instanceof WP_Post
            || $post->post_type !== 'page'
            || (int) $post->ID !== $front_page_id
            || $post->post_name !== 'home') {
            return $settings;
        }

        $settings['templateLock'] = 'all';
        $settings['canLockBlocks'] = false;
        $settings['codeEditingEnabled'] = false;

        return $settings;
    }
}
