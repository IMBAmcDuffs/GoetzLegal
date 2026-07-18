<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use function Goetz\Site\site_health_result;
use function Goetz\Site\site_health_test;

if (! class_exists('WP_Block_Type_Registry')) {
    final class WP_Block_Type_Registry
    {
        private static ?self $instance = null;

        /** @var array<int, string> */
        private array $registeredNames = [];

        public static function get_instance(): self
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        /** @param array<int, string> $names */
        public function setRegisteredNames(array $names): void
        {
            $this->registeredNames = $names;
        }

        public function is_registered(string $name): bool
        {
            return in_array($name, $this->registeredNames, true);
        }
    }
}

if (! defined('GOETZ_SITE_PATH')) {
    define('GOETZ_SITE_PATH', sys_get_temp_dir() . '/goetz-health-runtime/');
}

$functions_file = dirname(__DIR__, 2) . '/includes/functions.php';
if (is_readable($functions_file)) {
    require_once $functions_file;
}

final class HealthTest extends TestCase
{
    /** @var array<int, string> */
    private const STABLE_BLOCK_NAMES = [
        'goetz/attorney-card',
        'goetz/attorney-grid',
        'goetz/cta',
        'goetz/faq-list',
        'goetz/hero',
        'goetz/practice-area-item',
        'goetz/practice-areas',
        'goetz/resource-links',
        'goetz/welcome',
    ];

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg(1);
        Functions\when('wp_script_is')->justReturn(true);
        $this->root = sys_get_temp_dir() . '/goetz-health-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        mkdir(GOETZ_SITE_PATH . 'build', 0777, true);
        file_put_contents(GOETZ_SITE_PATH . 'build/index.js', '/* built */');
        file_put_contents(GOETZ_SITE_PATH . 'build/index.asset.php', '<?php return [];');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        $this->removeTree(rtrim(GOETZ_SITE_PATH, '/'));
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_missing_editor_bundle_is_a_non_fatal_critical_result(): void
    {
        self::assertTrue(function_exists('Goetz\\Site\\site_health_result'), 'The plugin Site Health check is missing.');
        $result = site_health_result(self::STABLE_BLOCK_NAMES, false);

        self::assertSame('critical', $result['status']);
        self::assertSame('goetz_site_runtime_assets', $result['test']);
    }

    public function test_complete_runtime_and_editor_bundle_are_good(): void
    {
        self::assertTrue(function_exists('Goetz\\Site\\site_health_result'), 'The plugin Site Health check is missing.');
        self::assertSame('good', site_health_result(self::STABLE_BLOCK_NAMES, true)['status']);
    }

    /**
     * @dataProvider stableBlockProvider
     */
    public function test_every_stable_block_is_required(string $missingBlock): void
    {
        $registeredNames = array_values(array_diff(self::STABLE_BLOCK_NAMES, [$missingBlock]));

        self::assertSame('critical', site_health_result($registeredNames, true)['status']);
    }

    /** @return array<string, array{string}> */
    public function stableBlockProvider(): array
    {
        $cases = [];
        foreach (self::STABLE_BLOCK_NAMES as $blockName) {
            $cases[$blockName] = [$blockName];
        }

        return $cases;
    }

    public function test_site_health_uses_actual_wordpress_registry_state(): void
    {
        $registry = WP_Block_Type_Registry::get_instance();
        $registry->setRegisteredNames([
            'goetz/attorney-card',
            'goetz/attorney-grid',
            'goetz/cta',
            'goetz/faq-list',
            'goetz/hero',
        ]);
        self::assertSame('critical', site_health_test()['status']);

        $registry->setRegisteredNames(self::STABLE_BLOCK_NAMES);
        self::assertSame('good', site_health_test()['status']);
    }

    public function test_unregistered_editor_handle_is_critical_even_when_build_files_exist(): void
    {
        Functions\when('wp_script_is')->justReturn(false);
        WP_Block_Type_Registry::get_instance()->setRegisteredNames(self::STABLE_BLOCK_NAMES);

        self::assertSame('critical', site_health_test()['status']);
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || ! file_exists($path)) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
