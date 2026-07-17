<?php

declare(strict_types=1);

namespace Goetz\Site\Settings;

final class Settings_Page
{
    private const PAGE_SLUG = 'goetz-site-settings';
    private const SECTION_ID = 'goetz-site-settings-main';

    /**
     * @var array<string, array{label: string, type: string, description?: string}>
     */
    private const FIELDS = [
        'business_name'          => ['label' => 'Business name', 'type' => 'text'],
        'alternate_name'         => ['label' => 'Alternate name', 'type' => 'text'],
        'phone_display'          => ['label' => 'Phone display', 'type' => 'text'],
        'phone_e164'             => ['label' => 'Phone E.164', 'type' => 'text', 'description' => 'Use + followed by 8 to 15 digits.'],
        'email'                  => ['label' => 'Email', 'type' => 'email'],
        'street_address'         => ['label' => 'Street address', 'type' => 'text'],
        'locality'               => ['label' => 'Locality', 'type' => 'text'],
        'region'                 => ['label' => 'Region', 'type' => 'text'],
        'postal_code'            => ['label' => 'Postal code', 'type' => 'text'],
        'country_code'           => ['label' => 'Country code', 'type' => 'text'],
        'location_label'         => ['label' => 'Location label', 'type' => 'text'],
        'cta_label'              => ['label' => 'CTA label', 'type' => 'text'],
        'cta_url'                => ['label' => 'CTA URL', 'type' => 'text', 'description' => 'Use a root-relative path or an HTTP(S) URL.'],
        'footer_disclaimer'      => ['label' => 'Footer disclaimer', 'type' => 'textarea'],
        'footer_legal_copy'      => ['label' => 'Footer legal copy', 'type' => 'textarea'],
        'copyright_start_year'   => ['label' => 'Copyright start year', 'type' => 'number'],
        'copyright_text'         => ['label' => 'Copyright text', 'type' => 'text'],
        'copyright_dynamic_year' => ['label' => 'Show current copyright year', 'type' => 'checkbox'],
        'social_image_id'        => ['label' => 'Social image', 'type' => 'image'],
    ];

    public static function hooks(): void
    {
        add_action('admin_menu', static function (): void {
            add_options_page(
                __('Site Settings', 'goetz-site'),
                __('Site Settings', 'goetz-site'),
                'manage_options',
                self::PAGE_SLUG,
                [self::class, 'render']
            );
        });
        add_action('admin_init', [self::class, 'register']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function register(): void
    {
        register_setting(Site_Settings::OPTION_GROUP, Site_Settings::OPTION_NAME, [
            'type'              => 'array',
            'sanitize_callback' => [Site_Settings::class, 'sanitize'],
            'default'           => Site_Settings::defaults(),
        ]);

        add_settings_section(
            self::SECTION_ID,
            __('Business and site-wide content', 'goetz-site'),
            static function (): void {
                echo '<p>' . esc_html__('These values are used by the theme, structured data, and default calls to action.', 'goetz-site') . '</p>';
            },
            self::PAGE_SLUG
        );

        foreach (self::FIELDS as $key => $field) {
            add_settings_field(
                $key,
                __($field['label'], 'goetz-site'),
                static function (array $args): void {
                    self::render_field((string) $args['key']);
                },
                self::PAGE_SLUG,
                self::SECTION_ID,
                [
                    'key'       => $key,
                    'label_for' => self::field_id($key),
                ]
            );
        }
    }

    public static function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(
                esc_html__('You are not allowed to access Site Settings.', 'goetz-site'),
                esc_html__('Forbidden', 'goetz-site'),
                ['response' => 403]
            );
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Site Settings', 'goetz-site'); ?></h1>
            <form action="options.php" method="post" data-goetz-site-settings-form>
                <?php settings_fields(Site_Settings::OPTION_GROUP); ?>
                <?php do_settings_sections(self::PAGE_SLUG); ?>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public static function enqueue(string $hook_suffix): void
    {
        if ($hook_suffix !== 'settings_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_media();
        $asset_path = GOETZ_SITE_PATH . 'assets/js/settings-media.js';
        wp_enqueue_script(
            'goetz-site-settings-media',
            GOETZ_SITE_URL . 'assets/js/settings-media.js',
            ['media-editor'],
            is_readable($asset_path) ? (string) filemtime($asset_path) : '1.0.0',
            true
        );
    }

    private static function render_field(string $key): void
    {
        if (! isset(self::FIELDS[$key])) {
            return;
        }

        $field = self::FIELDS[$key];
        $value = Site_Settings::get($key, Site_Settings::defaults()[$key] ?? '');
        $id = self::field_id($key);
        $name = Site_Settings::OPTION_NAME . '[' . $key . ']';
        $data_attribute = ' data-goetz-setting-key="' . esc_attr($key) . '"';

        if ($field['type'] === 'textarea') {
            printf(
                '<textarea class="large-text" rows="6" id="%1$s" name="%2$s"%3$s>%4$s</textarea>',
                esc_attr($id),
                esc_attr($name),
                $data_attribute,
                esc_textarea((string) $value)
            );
        } elseif ($field['type'] === 'checkbox') {
            printf(
                '<input type="hidden" name="%1$s" value="0"><input type="checkbox" id="%2$s" name="%1$s" value="1"%3$s%4$s>',
                esc_attr($name),
                esc_attr($id),
                checked((bool) $value, true, false),
                $data_attribute
            );
        } elseif ($field['type'] === 'image') {
            self::render_image_field($id, $name, (int) $value, $data_attribute);
        } else {
            $attributes = $field['type'] === 'number' ? ' min="1000" max="9999" step="1"' : '';
            printf(
                '<input class="regular-text" type="%1$s" id="%2$s" name="%3$s" value="%4$s"%5$s%6$s>',
                esc_attr($field['type']),
                esc_attr($id),
                esc_attr($name),
                esc_attr((string) $value),
                $attributes,
                $data_attribute
            );
        }

        if (isset($field['description'])) {
            echo '<p class="description">' . esc_html__($field['description'], 'goetz-site') . '</p>';
        }
    }

    private static function render_image_field(string $id, string $name, int $attachment_id, string $data_attribute): void
    {
        printf(
            '<input type="hidden" id="%1$s" name="%2$s" value="%3$d"%4$s data-goetz-media-id>',
            esc_attr($id),
            esc_attr($name),
            $attachment_id,
            $data_attribute
        );
        echo '<div data-goetz-media-preview>';
        if ($attachment_id > 0) {
            echo wp_get_attachment_image(
                $attachment_id,
                'thumbnail',
                false
            );
        }
        echo '</div>';
        echo '<p>';
        echo '<button type="button" class="button" data-goetz-media-select>' . esc_html__('Choose image', 'goetz-site') . '</button> ';
        echo '<button type="button" class="button-link-delete" data-goetz-media-remove>' . esc_html__('Remove image', 'goetz-site') . '</button>';
        echo '</p>';
    }

    private static function field_id(string $key): string
    {
        return 'goetz-site-setting-' . str_replace('_', '-', $key);
    }
}
