<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Goetz\Site\SEO\Schema;
use Goetz\Site\SEO\Yoast_Configurator;
use Goetz\Site\Settings\Site_Settings;
use PHPUnit\Framework\TestCase;

if (! class_exists('WPSEO_Options')) {
    final class WPSEO_Options
    {
        /** @var array<string, array<string, mixed>> */
        public static array $values = [];

        /** @var array<int, array{string, mixed, string}> */
        public static array $set_calls = [];

        /** @var callable|null */
        public static $on_set = null;

        public static function get(string $key, mixed $default = null, array $groups = []): mixed
        {
            $group = isset($groups[0]) ? (string) $groups[0] : '';

            return array_key_exists($key, self::$values[$group] ?? [])
                ? self::$values[$group][$key]
                : $default;
        }

        public static function set(string $key, mixed $value, string $group = ''): bool
        {
            self::$set_calls[] = [$key, $value, $group];
            self::$values[$group][$key] = $value;
            if (is_callable(self::$on_set)) {
                (self::$on_set)($group, $key);
            }

            return true;
        }

        public static function clear_cache(): void
        {
        }
    }
}

$settings_class = GOETZ_TEST_ROOT . '/wp-content/plugins/goetz-site/includes/settings/class-site-settings.php';
if (is_readable($settings_class)) {
    require_once $settings_class;
}

$configurator_class = GOETZ_TEST_ROOT . '/wp-content/plugins/goetz-site/includes/seo/class-yoast-configurator.php';
if (is_readable($configurator_class)) {
    require_once $configurator_class;
}

$schema_class = GOETZ_TEST_ROOT . '/wp-content/plugins/goetz-site/includes/seo/class-schema.php';
if (is_readable($schema_class)) {
    require_once $schema_class;
}

final class YoastConfiguratorTest extends TestCase
{
    /** @var array<string, object|null> */
    private array $pages = [];

    /** @var array<int, array<string, string>> */
    private array $post_meta = [];

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, array<string, mixed>> */
    private array $raw_yoast_options = [];

    /** @var array<int, string> */
    private array $mutation_log = [];

    private int $logo_id = 11;

    private int $social_image_id = 22;

    /** @var array{width: int, height: int} */
    private array $social_dimensions = ['width' => 1200, 'height' => 630];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->pages = [];
        $this->post_meta = [];
        $id = 100;
        foreach (array_keys($this->expected_fixture()) as $slug) {
            ++$id;
            $this->pages[$slug] = (object) [
                'ID'          => $id,
                'post_type'   => 'page',
                'post_name'   => $slug,
                'post_status' => 'publish',
            ];
            $this->post_meta[$id] = [
                '_yoast_wpseo_canonical' => 'http://localhost:8080/' . $slug . '/',
                '_yoast_wpseo_focuskw'   => 'preserve-' . $slug,
            ];
        }

        $this->options = [
            Site_Settings::OPTION_NAME => array_merge(Site_Settings::defaults(), [
                'social_image_id' => $this->social_image_id,
            ]),
        ];
        $this->raw_yoast_options = [
            'wpseo' => [
                'googleverify'  => 'verification-value',
                'myyoast-oauth' => [
                    'config'        => ['clientId' => 'client-id', 'secret' => 'client-secret'],
                    'access_tokens' => [['access_token' => 'oauth-token']],
                ],
                'semrush_tokens' => [['access_token' => 'semrush-token']],
                'wincher_tokens' => [['access_token' => 'wincher-token']],
            ],
            'wpseo_titles' => [
                'separator' => 'sc-dash',
            ],
            'wpseo_social' => [
                'pinterestverify' => 'pinterest-verification',
                'other_social_urls' => ['https://social.example/profile'],
            ],
        ];
        WPSEO_Options::$values = $this->raw_yoast_options;
        WPSEO_Options::$set_calls = [];
        WPSEO_Options::$on_set = function (string $group, string $key): void {
            $this->mutation_log[] = 'yoast:' . $group . ':' . $key;
        };
        $this->mutation_log = [];
        $this->logo_id = 11;
        $this->social_image_id = 22;
        $this->social_dimensions = ['width' => 1200, 'height' => 630];

        Functions\when('get_option')->alias(function (string $name, mixed $default = false): mixed {
            if (array_key_exists($name, $this->options)) {
                return $this->options[$name];
            }
            if (array_key_exists($name, $this->raw_yoast_options)) {
                return $this->raw_yoast_options[$name];
            }

            return $default;
        });
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->mutation_log[] = 'option:' . $name;
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('get_theme_mod')->alias(fn(string $name, mixed $default = false): mixed => $name === 'custom_logo' ? $this->logo_id : $default);
        Functions\when('get_post_type')->alias(static fn(int $post_id): ?string => in_array($post_id, [11, 22], true) ? 'attachment' : null);
        Functions\when('wp_attachment_is_image')->alias(static fn(int $post_id): bool => in_array($post_id, [11, 22], true));
        Functions\when('wp_get_attachment_url')->alias(static fn(int $post_id): string|false => match ($post_id) {
            11 => 'http://localhost:8080/wp-content/uploads/2026/07/goetz-logo.png',
            22 => 'http://localhost:8080/wp-content/uploads/2026/07/goetz-social-1200x630.jpg',
            default => false,
        });
        Functions\when('wp_get_attachment_metadata')->alias(function (int $post_id): array|false {
            if ($post_id === $this->social_image_id) {
                return $this->social_dimensions;
            }
            if ($post_id === $this->logo_id) {
                return ['width' => 600, 'height' => 160];
            }

            return false;
        });
        Functions\when('get_page_by_path')->alias(fn(string $slug, string $output = 'OBJECT', string $post_type = 'page'): object|null => $this->pages[$slug] ?? null);
        Functions\when('metadata_exists')->alias(fn(string $type, int $post_id, string $key): bool => array_key_exists($key, $this->post_meta[$post_id] ?? []));
        Functions\when('get_post_meta')->alias(fn(int $post_id, string $key, bool $single = false): mixed => $single
            ? ($this->post_meta[$post_id][$key] ?? '')
            : (array_key_exists($key, $this->post_meta[$post_id] ?? []) ? [$this->post_meta[$post_id][$key]] : []));
        Functions\when('update_post_meta')->alias(function (int $post_id, string $key, mixed $value): int|bool {
            $this->mutation_log[] = 'meta:' . $post_id . ':' . $key;
            $this->post_meta[$post_id][$key] = (string) $value;

            return 1;
        });
        Functions\when('delete_post_meta')->alias(function (int $post_id, string $key): bool {
            $this->mutation_log[] = 'delete-meta:' . $post_id . ':' . $key;
            unset($this->post_meta[$post_id][$key]);

            return true;
        });
        Functions\when('sanitize_text_field')->alias(static fn(mixed $value): string => trim(strip_tags((string) $value)));
        Functions\when('sanitize_email')->alias(static fn(mixed $value): string => trim((string) $value));
        Functions\when('is_email')->alias(static fn(string $value): string|false => filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : false);
        Functions\when('wp_kses_post')->alias(static fn(mixed $value): string => (string) $value);
        Functions\when('esc_url_raw')->alias(static fn(mixed $value): string => trim((string) $value));
        Functions\when('absint')->alias(static fn(mixed $value): int => abs((int) $value));
        Functions\when('home_url')->alias(static fn(string $path = '/'): string => 'http://localhost:8080' . ($path === '/' ? '/' : $path));
        Functions\when('wp_json_encode')->alias(static fn(mixed $value, int $flags = 0): string|false => json_encode($value, $flags));
        Functions\when('add_filter')->justReturn(true);
        Functions\when('remove_filter')->justReturn(true);
    }

    protected function tearDown(): void
    {
        WPSEO_Options::$on_set = null;
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_php_configuration_matches_the_approved_exact_fixture(): void
    {
        $config_file = GOETZ_TEST_ROOT . '/wp-content/plugins/goetz-site/config/seo-pages.php';
        self::assertFileIsReadable($config_file, 'The portable SEO page configuration is missing.');
        $config = require $config_file;

        self::assertSame($this->expected_php_config(), $config);
    }

    public function test_unavailable_yoast_is_a_safe_skipped_result(): void
    {
        $this->assertConfiguratorExists();

        self::assertSame([
            'status'          => 'skipped',
            'changed_options' => 0,
            'changed_pages'   => 0,
            'changed_meta'    => 0,
            'changed_version' => 0,
            'message'         => 'Yoast SEO is unavailable.',
        ], (new Yoast_Configurator(false))->configure());
        self::assertSame([], WPSEO_Options::$set_calls);
        self::assertSame([], $this->mutation_log);
    }

    public function test_desired_options_are_the_exact_audited_yoast_28_allowlist(): void
    {
        $this->assertConfiguratorExists();

        self::assertSame([
            'wpseo' => [
                'enable_xml_sitemap'         => true,
                'enable_schema'              => true,
                'tracking'                   => false,
                'semrush_integration_active' => false,
                'wincher_integration_active' => false,
            ],
            'wpseo_titles' => [
                'website_name'                    => 'Goetz & Goetz',
                'alternate_website_name'          => 'Goetz and Goetz',
                'company_or_person'               => 'company',
                'company_name'                    => 'Goetz & Goetz',
                'company_alternate_name'          => 'Goetz and Goetz',
                'company_logo'                    => 'http://localhost:8080/wp-content/uploads/2026/07/goetz-logo.png',
                'company_logo_id'                 => 11,
                'org-phone'                       => '+12399362841',
                'org-email'                       => 'info@goetzlegal.com',
                'disable-author'                  => true,
                'disable-date'                    => true,
                'disable-post_format'             => true,
                'disable-attachment'              => true,
                'noindex-page'                    => false,
                'noindex-post'                    => true,
                'noindex-attachment'              => true,
                'noindex-tax-category'            => true,
                'noindex-tax-post_tag'            => true,
                'open_graph_frontpage_image'      => 'http://localhost:8080/wp-content/uploads/2026/07/goetz-social-1200x630.jpg',
                'open_graph_frontpage_image_id'   => 22,
            ],
            'wpseo_social' => [
                'opengraph'             => true,
                'twitter'               => true,
                'twitter_card_type'     => 'summary_large_image',
                'og_default_image'      => 'http://localhost:8080/wp-content/uploads/2026/07/goetz-social-1200x630.jpg',
                'og_default_image_id'   => 22,
            ],
        ], (new Yoast_Configurator(true))->desired_option_values(11, 22));
    }

    public function test_strict_preflight_blocks_all_writes_when_any_approved_page_is_missing(): void
    {
        $this->assertConfiguratorExists();
        $this->pages['staff'] = null;

        $result = (new Yoast_Configurator(true))->configure();

        self::assertSame('blocked', $result['status']);
        self::assertContains('Required published page is missing or invalid: staff.', $result['errors']);
        self::assertSame(0, $result['changed_options']);
        self::assertSame(0, $result['changed_pages']);
        self::assertSame([], WPSEO_Options::$set_calls);
        self::assertSame([], $this->mutation_log);
    }

    public function test_strict_preflight_requires_a_valid_logo_and_exact_social_dimensions(): void
    {
        $this->assertConfiguratorExists();
        $this->logo_id = 0;
        $this->social_dimensions = ['width' => 1199, 'height' => 630];

        $result = (new Yoast_Configurator(true))->configure(true);

        self::assertSame('blocked', $result['status']);
        self::assertContains('A valid custom logo image attachment is required.', $result['errors']);
        self::assertContains('The Site Settings social image must be exactly 1200x630 pixels.', $result['errors']);
        self::assertSame([], WPSEO_Options::$set_calls);
        self::assertSame([], $this->mutation_log);
    }

    public function test_dry_run_reports_the_complete_diff_without_writing_any_state(): void
    {
        $this->assertConfiguratorExists();
        $meta_before = $this->post_meta;
        $raw_before = $this->raw_yoast_options;

        $result = (new Yoast_Configurator(true))->configure(true);

        self::assertSame('dry-run', $result['status']);
        self::assertGreaterThan(0, $result['changed_options']);
        self::assertSame(7, $result['changed_pages']);
        self::assertSame(49, $result['changed_meta']);
        self::assertSame(1, $result['changed_version']);
        self::assertSame([], WPSEO_Options::$set_calls);
        self::assertSame([], $this->mutation_log);
        self::assertSame($meta_before, $this->post_meta);
        self::assertSame($raw_before, $this->raw_yoast_options);
    }

    public function test_apply_changes_only_allowlisted_values_preserves_unmanaged_data_and_second_apply_is_zero(): void
    {
        $this->assertConfiguratorExists();
        $unmanaged_before = $this->serialized_unmanaged_values();

        $first = (new Yoast_Configurator(true))->configure(false);

        self::assertSame('configured', $first['status']);
        self::assertSame(7, $first['changed_pages']);
        self::assertSame(49, $first['changed_meta']);
        self::assertSame(1, $first['changed_version']);
        self::assertSame(Yoast_Configurator::SCHEMA_VERSION, $this->options[Yoast_Configurator::VERSION_OPTION]);
        self::assertSame($unmanaged_before, $this->serialized_unmanaged_values());

        $allowed = (new Yoast_Configurator(true))->desired_option_values(11, 22);
        $expected_calls = [];
        foreach ($allowed as $group => $values) {
            foreach ($values as $key => $value) {
                $expected_calls[] = [$key, $value, $group];
            }
        }
        self::assertSame($expected_calls, WPSEO_Options::$set_calls);
        foreach ($this->pages as $slug => $page) {
            self::assertIsObject($page);
            $page_id = (int) $page->ID;
            self::assertArrayNotHasKey('_yoast_wpseo_canonical', $this->post_meta[$page_id]);
            self::assertSame('preserve-' . $slug, $this->post_meta[$page_id]['_yoast_wpseo_focuskw']);
            self::assertSame((string) $this->social_image_id, $this->post_meta[$page_id]['_yoast_wpseo_opengraph-image-id']);
            self::assertSame((string) $this->social_image_id, $this->post_meta[$page_id]['_yoast_wpseo_twitter-image-id']);
        }

        WPSEO_Options::$set_calls = [];
        $this->mutation_log = [];
        $second = (new Yoast_Configurator(true))->configure(false);

        self::assertSame('noop', $second['status']);
        self::assertSame(0, $second['changed_options']);
        self::assertSame(0, $second['changed_pages']);
        self::assertSame(0, $second['changed_meta']);
        self::assertSame(0, $second['changed_version']);
        self::assertSame([], WPSEO_Options::$set_calls);
        self::assertSame([], $this->mutation_log);
    }

    public function test_schema_extends_the_yoast_piece_without_losing_identity_or_graph_links(): void
    {
        $this->assertSchemaExists();
        $piece = [
            '@type'            => 'Organization',
            '@id'              => 'http://localhost:8080/#organization',
            'logo'             => ['@id' => 'http://localhost:8080/#logo'],
            'image'            => ['@id' => 'http://localhost:8080/#logo'],
            'sameAs'           => ['https://social.example/goetz'],
            'mainEntityOfPage' => ['@id' => 'http://localhost:8080/#webpage'],
        ];

        $filtered = Schema::filter_organization($piece);

        self::assertSame(['Organization', 'LegalService'], $filtered['@type']);
        foreach (['@id', 'logo', 'image', 'sameAs', 'mainEntityOfPage'] as $key) {
            self::assertSame($piece[$key], $filtered[$key]);
        }
        self::assertSame('http://localhost:8080/', $filtered['url']);
        self::assertSame('Goetz & Goetz', $filtered['name']);
        self::assertSame('Goetz and Goetz', $filtered['alternateName']);
        self::assertSame('+12399362841', $filtered['telephone']);
        self::assertSame('info@goetzlegal.com', $filtered['email']);
        self::assertSame([
            '@type'           => 'PostalAddress',
            'streetAddress'   => '33 Barkley Cir Ste 100',
            'addressLocality' => 'Fort Myers',
            'addressRegion'   => 'FL',
            'postalCode'      => '33907',
            'addressCountry'  => 'US',
        ], $filtered['address']);
        self::assertSame([
            '@type' => 'City',
            'name'  => 'Fort Myers, Florida',
        ], $filtered['areaServed']);
    }

    public function test_sitemaps_include_only_pages_and_no_taxonomies(): void
    {
        $this->assertSchemaExists();

        self::assertFalse(Schema::exclude_post_type(true, 'page'));
        self::assertTrue(Schema::exclude_post_type(false, 'post'));
        self::assertTrue(Schema::exclude_post_type(false, 'attachment'));
        self::assertTrue(Schema::exclude_post_type(false, 'custom_type'));
        self::assertTrue(Schema::exclude_taxonomy(false, 'category'));
        self::assertTrue(Schema::exclude_taxonomy(false, 'post_tag'));
        self::assertTrue(Schema::exclude_taxonomy(false, 'custom_taxonomy'));
    }

    public function test_plugin_owns_the_only_non_yoast_fallback_and_theme_emitter_is_removed(): void
    {
        $this->assertSchemaExists();
        $graph = Schema::fallback_graph();

        self::assertSame('https://schema.org', $graph['@context']);
        self::assertCount(1, $graph['@graph']);
        self::assertSame(['Organization', 'LegalService'], $graph['@graph'][0]['@type']);

        $theme_source = (string) file_get_contents(GOETZ_TEST_ROOT . '/wp-content/themes/goetz-legal/functions.php');
        self::assertStringNotContainsString('goetz_legal_schema_fallback', $theme_source);
        self::assertStringNotContainsString('application/ld+json', $theme_source);
    }

    /**
     * @return array<string, array{string, string}>
     */
    private function expected_fixture(): array
    {
        return [
            'home' => [
                'Fort Myers Trial Attorneys | Goetz & Goetz',
                'Goetz & Goetz provides experienced legal counsel in Fort Myers for corporate, construction, real estate, probate, criminal and bankruptcy matters.',
            ],
            'james-l-goetz' => [
                'James L. Goetz, Attorney | Goetz & Goetz',
                'Learn about James L. Goetz, a Fort Myers attorney with more than 50 years of experience in trial, probate, real estate and commercial litigation.',
            ],
            'gregory-w-goetz' => [
                'Gregory W. Goetz, Attorney | Goetz & Goetz',
                'Learn about Gregory W. Goetz, a Fort Myers attorney serving clients in Florida state and federal courts across a range of legal matters.',
            ],
            'staff' => [
                'Legal Team and Staff | Goetz & Goetz',
                'Meet the attorneys and legal staff at Goetz & Goetz in Fort Myers, Florida, and find direct contact information for the firm.',
            ],
            'questions' => [
                'Florida Legal Questions | Goetz & Goetz',
                'Read answers from Goetz & Goetz to common Florida legal questions about construction, homestead protection, wills, real estate and dispute resolution.',
            ],
            'links' => [
                'Florida and Federal Legal Links | Goetz & Goetz',
                'Find useful Florida and federal court, government, bar association, property, tax and legal resources selected by Goetz & Goetz.',
            ],
            'contact' => [
                'Contact Goetz & Goetz | Fort Myers Attorneys',
                'Contact Goetz & Goetz in Fort Myers, Florida, by phone, email or online form to discuss your legal questions and request a consultation.',
            ],
        ];
    }

    /**
     * @return array<string, array{title: string, description: string}>
     */
    private function expected_php_config(): array
    {
        $config = [];
        foreach ($this->expected_fixture() as $slug => $values) {
            $config[$slug] = [
                'title'       => $values[0],
                'description' => $values[1],
            ];
        }

        return $config;
    }

    /** @return array<string, string> */
    private function serialized_unmanaged_values(): array
    {
        return [
            'googleverify'      => serialize($this->raw_yoast_options['wpseo']['googleverify']),
            'myyoast-oauth'     => serialize($this->raw_yoast_options['wpseo']['myyoast-oauth']),
            'semrush_tokens'    => serialize($this->raw_yoast_options['wpseo']['semrush_tokens']),
            'wincher_tokens'    => serialize($this->raw_yoast_options['wpseo']['wincher_tokens']),
            'separator'         => serialize($this->raw_yoast_options['wpseo_titles']['separator']),
            'pinterestverify'   => serialize($this->raw_yoast_options['wpseo_social']['pinterestverify']),
            'other_social_urls' => serialize($this->raw_yoast_options['wpseo_social']['other_social_urls']),
        ];
    }

    private function assertConfiguratorExists(): void
    {
        self::assertTrue(class_exists(Yoast_Configurator::class), 'The Yoast configurator is missing.');
    }

    private function assertSchemaExists(): void
    {
        self::assertTrue(class_exists(Schema::class), 'The portable SEO schema runtime is missing.');
    }
}
