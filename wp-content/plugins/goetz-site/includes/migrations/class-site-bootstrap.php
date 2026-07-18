<?php

declare(strict_types=1);

namespace Goetz\Site\Migrations;

use Goetz\Site\Settings\Site_Settings;
use RuntimeException;
use WP_Post;
use WP_Term;

final class Site_Bootstrap
{
    private const MENU_META_KEY = '_goetz_site_menu_seed_key';

    /** @var array<string, mixed> */
    private array $config;

    private Media_Seeder $seeder;

    /**
     * @param array<string, mixed>|null $config
     */
    public function __construct(?array $config = null, ?Media_Seeder $seeder = null)
    {
        $this->config = $config ?? Media_Seeder::load_config();
        $this->seeder = $seeder ?? new Media_Seeder($this->config);
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(): array
    {
        $locations = $this->menu_locations();
        $settings = $this->raw_settings();

        $actions = [];
        foreach (array_keys($this->menus()) as $location) {
            if ((int) ($locations[$location] ?? 0) < 1) {
                $actions[] = 'assign_menu:' . $location;
            }
        }
        if (array_filter(
            $actions,
            static fn(string $action): bool => str_starts_with($action, 'assign_menu:')
        ) !== []) {
            $pages = $this->resolve_menu_pages();
            if (isset($pages['missing'])) {
                return [
                    'status'        => 'blocked',
                    'missing_pages' => $pages['missing'],
                    'actions'       => [],
                ];
            }
        }
        if ((int) get_theme_mod('custom_logo', 0) < 1) {
            $actions[] = 'set_custom_logo';
        }
        if (absint($settings['social_image_id'] ?? 0) < 1) {
            $actions[] = 'set_social_image';
        }

        return [
            'status'  => $actions === [] ? 'noop' : 'ready',
            'actions' => $actions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(): array
    {
        $plan = $this->plan();
        if (($plan['status'] ?? '') === 'blocked') {
            throw new RuntimeException(
                'Site bootstrap requires every configured navigation page: '
                . implode(', ', $plan['missing_pages'] ?? [])
            );
        }
        if (($plan['status'] ?? '') === 'noop') {
            return ['status' => 'noop', 'actions' => []];
        }

        $theme_mods_option = 'theme_mods_' . get_option('stylesheet');
        $theme_mods_before = $this->snapshot_option($theme_mods_option);
        $settings_before = $this->snapshot_option(Site_Settings::OPTION_NAME);
        $menus_before = $this->menu_ids();
        $attachments_before = $this->attachment_state();
        $actions = [];
        $created_menu_ids = [];
        $touched_attachment_ids = [];

        try {
            $locations = $this->menu_locations();
            $needs_menu = false;
            foreach (array_keys($this->menus()) as $location) {
                if ((int) ($locations[$location] ?? 0) < 1) {
                    $needs_menu = true;
                }
            }
            $pages = $needs_menu ? $this->resolve_menu_pages() : ['pages' => []];
            if (isset($pages['missing'])) {
                throw new RuntimeException('Site bootstrap page resolution changed unexpectedly.');
            }
            $assigned_menu = false;
            foreach ($this->menus() as $location => $menu_config) {
                if ((int) ($locations[$location] ?? 0) > 0) {
                    continue;
                }

                $menu_id = $this->ensure_menu($location, $menu_config, $pages['pages']);
                if (! in_array($menu_id, $menus_before, true)) {
                    $created_menu_ids[] = $menu_id;
                }
                $locations[$location] = $menu_id;
                $actions[] = 'assign_menu:' . $location;
                $assigned_menu = true;
            }
            if ($assigned_menu) {
                set_theme_mod('nav_menu_locations', $locations);
                if ($this->menu_locations() !== $locations) {
                    throw new RuntimeException('Could not persist the native menu assignments.');
                }
            }

            if ((int) get_theme_mod('custom_logo', 0) < 1) {
                $logo_id = $this->seeder->seed('header_logo');
                $touched_attachment_ids[] = $logo_id;
                set_theme_mod('custom_logo', $logo_id);
                if ((int) get_theme_mod('custom_logo', 0) !== $logo_id) {
                    throw new RuntimeException('Could not persist the default Custom Logo.');
                }
                $actions[] = 'set_custom_logo';
            }

            $settings = $this->raw_settings();
            if (absint($settings['social_image_id'] ?? 0) < 1) {
                $settings['social_image_id'] = $this->seeder->seed('social_image');
                $touched_attachment_ids[] = (int) $settings['social_image_id'];
                if (! update_option(Site_Settings::OPTION_NAME, $settings)) {
                    $stored = get_option(Site_Settings::OPTION_NAME, []);
                    if ($stored !== $settings) {
                        throw new RuntimeException('Could not merge the default social image setting.');
                    }
                }
                $actions[] = 'set_social_image';
            }

            return [
                'status'  => $actions === [] ? 'noop' : 'updated',
                'actions' => $actions,
                'created_menu_ids' => array_values(array_unique($created_menu_ids)),
            ];
        } catch (\Throwable $exception) {
            $this->restore_option($theme_mods_option, $theme_mods_before);
            $this->restore_option(Site_Settings::OPTION_NAME, $settings_before);
            $this->remove_menus($created_menu_ids);
            $this->restore_attachment_state($attachments_before, $touched_attachment_ids);
            throw $exception;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function menus(): array
    {
        $menus = $this->config['menus'] ?? null;
        if (! is_array($menus) || array_keys($menus) !== ['primary', 'footer']) {
            throw new RuntimeException('Homepage menu configuration is invalid.');
        }

        return $menus;
    }

    /**
     * @return array{pages: array<string, int>}|array{missing: array<int, string>}
     */
    private function resolve_menu_pages(): array
    {
        $slugs = [];
        foreach ($this->menus() as $menu) {
            if (! isset($menu['items']) || ! is_array($menu['items'])) {
                throw new RuntimeException('Homepage menu items are invalid.');
            }
            foreach ($menu['items'] as $item) {
                if (! is_array($item)
                    || ! isset($item['label'], $item['slug'])
                    || ! is_string($item['label'])
                    || ! is_string($item['slug'])) {
                    throw new RuntimeException('Homepage menu item configuration is invalid.');
                }
                $slugs[$item['slug']] = true;
            }
        }

        $pages = [];
        $missing = [];
        foreach (array_keys($slugs) as $slug) {
            if ($slug === 'home') {
                $post = get_post((int) get_option('page_on_front'));
                if (! $post instanceof WP_Post || $post->post_type !== 'page' || $post->post_name !== 'home') {
                    $missing[] = $slug;
                    continue;
                }
            } else {
                $post = get_page_by_path($slug, OBJECT, 'page');
                if (! $post instanceof WP_Post || $post->post_name !== $slug) {
                    $missing[] = $slug;
                    continue;
                }
            }
            if ($post->post_status !== 'publish') {
                $missing[] = $slug;
                continue;
            }
            $pages[$slug] = (int) $post->ID;
        }

        return $missing === [] ? ['pages' => $pages] : ['missing' => $missing];
    }

    /**
     * @param array<string, mixed> $menu_config
     * @param array<string, int> $pages
     */
    private function ensure_menu(string $location, array $menu_config, array $pages): int
    {
        foreach (['seed_key', 'name'] as $field) {
            if (! isset($menu_config[$field]) || ! is_string($menu_config[$field]) || $menu_config[$field] === '') {
                throw new RuntimeException("Menu {$location} is missing {$field}.");
            }
        }
        $seed_key = (string) $menu_config['seed_key'];
        $matches = get_terms([
            'taxonomy'   => 'nav_menu',
            'hide_empty' => false,
            'meta_key'   => self::MENU_META_KEY,
            'meta_value' => $seed_key,
        ]);
        if (is_wp_error($matches)) {
            throw new RuntimeException("Could not inspect the {$location} menu.");
        }
        if (count($matches) > 1) {
            throw new RuntimeException("Menu {$location} has duplicate managed terms.");
        }
        if ($matches !== []) {
            $menu = $matches[0];
            if (! $menu instanceof WP_Term) {
                throw new RuntimeException("Menu {$location} has an invalid managed term.");
            }
            $this->validate_menu((int) $menu->term_id, $menu_config, $pages);

            return (int) $menu->term_id;
        }

        $same_name = wp_get_nav_menu_object((string) $menu_config['name']);
        if ($same_name instanceof WP_Term) {
            throw new RuntimeException("Menu {$location} conflicts with an unmanaged menu of the same name.");
        }

        $created = wp_create_nav_menu((string) $menu_config['name']);
        if (is_wp_error($created)) {
            throw new RuntimeException("Could not create the {$location} menu: " . $created->get_error_message());
        }
        $menu_id = (int) $created;
        if (! add_term_meta($menu_id, self::MENU_META_KEY, $seed_key, true)) {
            wp_delete_nav_menu($menu_id);
            throw new RuntimeException("Could not mark the {$location} menu as managed.");
        }

        foreach ($menu_config['items'] as $position => $item) {
            $slug = (string) $item['slug'];
            $inserted = wp_update_nav_menu_item($menu_id, 0, [
                'menu-item-object-id' => $pages[$slug],
                'menu-item-object'    => 'page',
                'menu-item-parent-id' => 0,
                'menu-item-position'  => $position + 1,
                'menu-item-title'     => (string) $item['label'],
                'menu-item-type'      => 'post_type',
                'menu-item-status'    => 'publish',
            ]);
            if (is_wp_error($inserted)) {
                wp_delete_nav_menu($menu_id);
                throw new RuntimeException("Could not create an item in the {$location} menu.");
            }
        }

        $this->validate_menu($menu_id, $menu_config, $pages);

        return $menu_id;
    }

    /**
     * @param array<string, mixed> $menu_config
     * @param array<string, int> $pages
     */
    private function validate_menu(int $menu_id, array $menu_config, array $pages): void
    {
        $items = wp_get_nav_menu_items($menu_id, [
            'orderby'     => 'menu_order',
            'order'       => 'ASC',
            'post_status' => 'publish',
        ]);
        if (! is_array($items) || count($items) !== count($menu_config['items'])) {
            throw new RuntimeException('Managed menu contents were edited; bootstrap is stopping.');
        }

        foreach ($menu_config['items'] as $index => $expected) {
            $item = $items[$index] ?? null;
            $slug = (string) $expected['slug'];
            if (! $item instanceof WP_Post
                || $item->title !== (string) $expected['label']
                || $item->type !== 'post_type'
                || $item->object !== 'page'
                || (int) $item->object_id !== $pages[$slug]
                || (int) $item->menu_order !== $index + 1) {
                throw new RuntimeException('Managed menu contents were edited; bootstrap is stopping.');
            }
        }
    }

    /**
     * @return array{exists: bool, value: mixed}
     */
    private function snapshot_option(string $name): array
    {
        $sentinel = '__goetz_site_missing_' . wp_generate_uuid4();
        $value = get_option($name, $sentinel);

        return ['exists' => $value !== $sentinel, 'value' => $value];
    }

    /**
     * @param array{exists: bool, value: mixed} $snapshot
     */
    private function restore_option(string $name, array $snapshot): void
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
    private function menu_ids(): array
    {
        return array_map(
            static fn(WP_Term $menu): int => (int) $menu->term_id,
            wp_get_nav_menus(['orderby' => 'term_id', 'order' => 'ASC'])
        );
    }

    /**
     * @param array<int, int> $before
     */
    private function remove_menus(array $menu_ids): void
    {
        foreach (array_unique(array_map('intval', $menu_ids)) as $menu_id) {
            if ($menu_id > 0) {
                wp_delete_nav_menu($menu_id);
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function menu_locations(): array
    {
        $locations = get_theme_mod('nav_menu_locations', null);
        if ($locations === null || $locations === false) {
            return [];
        }
        if (! is_array($locations)) {
            throw new RuntimeException('Existing navigation location settings are corrupt; bootstrap is stopping.');
        }

        return $locations;
    }

    /**
     * @return array<string, mixed>
     */
    private function raw_settings(): array
    {
        $sentinel = '__goetz_site_settings_missing_' . wp_generate_uuid4();
        $settings = get_option(Site_Settings::OPTION_NAME, $sentinel);
        if ($settings === $sentinel) {
            return [];
        }
        if (! is_array($settings)) {
            throw new RuntimeException('Existing Site Settings are corrupt; bootstrap is stopping.');
        }

        return $settings;
    }

    /**
     * @return array{ids: array<int, int>, seed_meta: array<int, array<int, mixed>>, checksum_meta: array<int, array<int, mixed>>}
     */
    private function attachment_state(): array
    {
        $ids = array_map('intval', get_posts([
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
        $seed_meta = [];
        $checksum_meta = [];
        foreach ($ids as $id) {
            $seed_meta[$id] = get_post_meta($id, Media_Seeder::META_KEY, false);
            $checksum_meta[$id] = get_post_meta($id, Media_Seeder::CHECKSUM_META_KEY, false);
        }

        return ['ids' => $ids, 'seed_meta' => $seed_meta, 'checksum_meta' => $checksum_meta];
    }

    /**
     * @param array{ids: array<int, int>, seed_meta: array<int, array<int, mixed>>, checksum_meta: array<int, array<int, mixed>>} $state
     */
    private function restore_attachment_state(array $state, array $touched_ids): void
    {
        $lookup = array_fill_keys($state['ids'], true);
        foreach (array_unique(array_map('intval', $touched_ids)) as $id) {
            if ($id < 1) {
                continue;
            }
            if (! isset($lookup[$id])) {
                if (get_post_type($id) === 'attachment') {
                    wp_delete_attachment($id, true);
                }
                continue;
            }
            delete_post_meta($id, Media_Seeder::META_KEY);
            delete_post_meta($id, Media_Seeder::CHECKSUM_META_KEY);
            foreach ($state['seed_meta'][$id] ?? [] as $value) {
                add_post_meta($id, Media_Seeder::META_KEY, $value);
            }
            foreach ($state['checksum_meta'][$id] ?? [] as $value) {
                add_post_meta($id, Media_Seeder::CHECKSUM_META_KEY, $value);
            }
        }
    }
}
