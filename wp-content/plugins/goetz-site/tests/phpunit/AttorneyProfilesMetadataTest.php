<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use Goetz\Site\Attorney_Profiles;
use PHPUnit\Framework\TestCase;

$attorney_profiles_class = dirname(__DIR__, 2) . '/includes/class-attorney-profiles.php';
if (is_readable($attorney_profiles_class)) {
    require_once $attorney_profiles_class;
}

final class AttorneyProfilesMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_already_persisted_generated_metadata_is_successful_without_a_redundant_write(): void
    {
        $metadata = $this->metadataFixture();
        Functions\expect('wp_get_attachment_metadata')
            ->once()
            ->with(222, true)
            ->andReturn($metadata);
        Functions\expect('wp_update_attachment_metadata')->never();

        self::assertTrue($this->persistMetadata(222, $metadata));
    }

    public function test_false_update_result_is_successful_when_exact_metadata_exists_on_readback(): void
    {
        $metadata = $this->metadataFixture();
        Functions\expect('wp_get_attachment_metadata')
            ->twice()
            ->with(222, true)
            ->andReturn(false, $metadata);
        Functions\expect('wp_update_attachment_metadata')
            ->once()
            ->with(222, $metadata)
            ->andReturn(false);

        self::assertTrue($this->persistMetadata(222, $metadata));
    }

    public function test_false_update_result_fails_closed_without_exact_metadata_readback(): void
    {
        $metadata = $this->metadataFixture();
        Functions\expect('wp_get_attachment_metadata')
            ->twice()
            ->with(222, true)
            ->andReturn(false, ['file' => 'wrong-file.jpg']);
        Functions\expect('wp_update_attachment_metadata')
            ->once()
            ->with(222, $metadata)
            ->andReturn(false);

        self::assertFalse($this->persistMetadata(222, $metadata));
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataFixture(): array
    {
        return [
            'width'    => 800,
            'height'   => 1200,
            'file'     => '2026/07/JAMES-L-2.jpg',
            'filesize' => 69605,
            'sizes'    => [],
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function persistMetadata(int $attachmentId, array $metadata): bool
    {
        $method = new ReflectionMethod(Attorney_Profiles::class, 'persist_attachment_metadata');
        $method->setAccessible(true);

        return (bool) $method->invoke(null, $attachmentId, $metadata);
    }
}
