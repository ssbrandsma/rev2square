<?php

namespace Tests\Unit\Artifacts;

use App\Services\Artifacts\ArtifactStorage;
use PHPUnit\Framework\TestCase;

class ArtifactStorageTest extends TestCase
{
    public function test_classify_distinguishes_present_missing_and_unreadable(): void
    {
        // Present and readable: usable regardless of ancestors.
        $this->assertSame(ArtifactStorage::OK, ArtifactStorage::classify(true, true, true));
        $this->assertSame(ArtifactStorage::OK, ArtifactStorage::classify(true, true, false));
        // Present but unreadable: permissions, never reported as deleted.
        $this->assertSame(ArtifactStorage::UNREADABLE, ArtifactStorage::classify(true, false, true));
        $this->assertSame(ArtifactStorage::UNREADABLE, ArtifactStorage::classify(true, false, false));
        // Absent with healthy ancestors: genuinely missing.
        $this->assertSame(ArtifactStorage::MISSING, ArtifactStorage::classify(false, false, true));
        // Absent with broken ancestors (unmounted volume, denied dir):
        // invisible, not proven deleted.
        $this->assertSame(ArtifactStorage::UNREADABLE, ArtifactStorage::classify(false, false, false));
        // Contradictory probe input (readable but absent) still resolves
        // by ancestor health; the table is total.
        $this->assertSame(ArtifactStorage::MISSING, ArtifactStorage::classify(false, true, true));
        $this->assertSame(ArtifactStorage::UNREADABLE, ArtifactStorage::classify(false, true, false));
    }

    public function test_unavailable_message_is_retry_first_and_path_free(): void
    {
        $message = ArtifactStorage::unavailableMessage();

        $this->assertStringContainsString('Retry', $message);
        $this->assertStringContainsString('re-upload only if', $message);
        $this->assertStringNotContainsString('/var/www', $message);
        $this->assertStringNotContainsString('storage/app', $message);
    }
}
