<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ThemePerformanceAssetsTest extends TestCase
{
    public function test_navigation_enhancement_is_declared_before_blocking_head_assets(): void
    {
        $header = (string) file_get_contents(
            GOETZ_TEST_ROOT . '/wp-content/themes/goetz-legal/header.php'
        );
        $enhancement = "root.classList.add('is-navigation-enhanced')";
        $enhancement_position = strpos($header, $enhancement);
        $wp_head_position = strpos($header, '<?php wp_head(); ?>');

        self::assertNotFalse($enhancement_position, 'The early navigation enhancement marker is missing.');
        self::assertNotFalse($wp_head_position, 'The theme header no longer calls wp_head().');
        self::assertLessThan(
            $wp_head_position,
            $enhancement_position,
            'The navigation enhancement marker must execute before blocking head assets.'
        );
    }

    public function test_roboto_is_metric_compatible_local_hash_pinned_and_licensed(): void
    {
        $theme = GOETZ_TEST_ROOT . '/wp-content/themes/goetz-legal';
        $functions = (string) file_get_contents($theme . '/functions.php');
        $styles = (string) file_get_contents($theme . '/resources/scss/custom.scss');
        $font = $theme . '/resources/fonts/roboto-latin-300-700.woff2';
        $license = $theme . '/resources/fonts/OFL.txt';

        self::assertStringNotContainsString('fonts.googleapis.com', $functions);
        self::assertStringNotContainsString('fonts.gstatic.com', $functions);
        self::assertStringContainsString(
            "'resources/fonts/roboto-latin-300-700.woff2'",
            $functions
        );
        self::assertStringContainsString('rel="preload"', $functions);
        self::assertStringContainsString(
            "add_action('wp_head', 'goetz_legal_preload_font', 1)",
            $functions
        );
        self::assertStringContainsString('@each $weight in 300, 400, 500, 700', $styles);
        self::assertStringContainsString('../fonts/roboto-latin-300-700.woff2', $styles);
        self::assertStringContainsString(
            '--font-body: "Roboto", "Helvetica Neue", Helvetica, Arial, sans-serif',
            $styles
        );
        self::assertFileIsReadable($font);
        self::assertSame(
            '1404ca348bd75ef836f4dd8b6f2cc719458642d1237c368296b2fc652dca47dc',
            hash_file('sha256', $font)
        );
        self::assertFileIsReadable($license);
        self::assertStringContainsString(
            'SIL OPEN FONT LICENSE Version 1.1',
            (string) file_get_contents($license)
        );
    }
}
