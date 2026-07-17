<?php

declare(strict_types=1);

use Goetz\Site\Blocks;
use PHPUnit\Framework\TestCase;

$blocks_class = dirname(__DIR__, 2) . '/includes/class-blocks.php';
if (is_readable($blocks_class)) {
    require_once $blocks_class;
}

final class BlocksTest extends TestCase
{
    /** @var array<int, string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            $this->removeTree($directory);
        }
    }

    public function test_public_api_remains_final_static_and_stable(): void
    {
        self::assertTrue(class_exists(Blocks::class), 'The stable Blocks API is missing.');
        $reflection = new ReflectionClass(Blocks::class);
        $publicMethods = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC)
        );
        sort($publicMethods);

        self::assertTrue($reflection->isFinal());
        self::assertSame(['names', 'register'], $publicMethods);
        self::assertTrue($reflection->getMethod('names')->isStatic());
        self::assertTrue($reflection->getMethod('register')->isStatic());
        self::assertSame('goetz-site-block-editor', Blocks::EDITOR_HANDLE);
    }

    public function test_names_only_returns_sorted_contained_first_party_directories(): void
    {
        self::assertTrue(class_exists(Blocks::class), 'The deterministic block scanner is missing.');

        $root = $this->temporaryDirectory('goetz-blocks');
        $outside = $this->temporaryDirectory('goetz-outside');

        $this->writeMetadata($root . '/zeta', 'goetz/zeta');
        $this->writeMetadata($root . '/alpha', 'goetz/alpha');
        $this->writeMetadata($root . '/foreign', 'vendor/foreign');
        $this->writeMetadata($root . '/nested/child', 'goetz/nested');
        $this->writeMetadata($outside . '/escaped', 'goetz/escaped');
        mkdir($root . '/missing');
        mkdir($root . '/invalid');
        file_put_contents($root . '/invalid/block.json', '{not-json');
        symlink($outside . '/escaped', $root . '/linked');

        $scan = new ReflectionMethod(Blocks::class, 'scan');
        $scan->setAccessible(true);
        $directories = $scan->invoke(null, $root);

        self::assertSame(['goetz/alpha', 'goetz/zeta'], array_keys($directories));
    }

    public function test_editor_assets_are_optional_for_server_registration_readiness(): void
    {
        self::assertTrue(class_exists(Blocks::class), 'The shared editor asset scanner is missing.');

        $root = $this->temporaryDirectory('goetz-assets');
        $build = $root . '/build';
        mkdir($build);
        $assetData = new ReflectionMethod(Blocks::class, 'editorAssetData');
        $assetData->setAccessible(true);

        self::assertNull($assetData->invoke(null, $build));

        file_put_contents($build . '/index.js', '/* built */');
        file_put_contents(
            $build . '/index.asset.php',
            "<?php return ['dependencies' => ['wp-blocks'], 'version' => 'test'];\n"
        );

        self::assertSame(
            ['dependencies' => ['wp-blocks'], 'version' => 'test'],
            $assetData->invoke(null, $build)
        );
    }

    private function temporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function writeMetadata(string $directory, string $name): void
    {
        mkdir($directory, 0777, true);
        file_put_contents($directory . '/block.json', json_encode(['name' => $name], JSON_THROW_ON_ERROR));
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
