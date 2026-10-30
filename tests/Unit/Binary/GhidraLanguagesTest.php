<?php

namespace Tests\Unit\Binary;

use App\Models\ProjectArtifact;
use App\Services\Binary\Adapters\Capability;
use App\Services\Binary\Adapters\GhidraAdapter;
use Tests\TestCase;

/**
 * Ghidra language mappings. VERIFIED ids were confirmed in the
 * Ghidra 12.2 .ldefs sources; this test pins them so neither drift
 * nor a well-meaning "fix" can silently change routing.
 */
class GhidraLanguagesTest extends TestCase
{
    public function test_verified_languages_are_pinned(): void
    {
        $this->assertSame([
            'x86' => 'x86:LE:32:default',
            'x86-64' => 'x86:LE:64:default',
            'arm32' => 'ARM:LE:32:v8',
            'arm32-thumb' => 'ARM:LE:32:v8T',
            'aarch64' => 'AARCH64:LE:64:v8A',
        ], GhidraAdapter::VERIFIED_LANGUAGES);
    }

    public function test_avr_is_absent_and_documented_ids_stay_candidates(): void
    {
        // Ghidra 12.2 ships no built-in AVR language (39 modules
        // verified, no AVR): an AVR mapping would route to a
        // guaranteed import failure, so it must not exist.
        $this->assertArrayNotHasKey('avr', GhidraAdapter::VERIFIED_LANGUAGES);
        $this->assertArrayNotHasKey('avr', GhidraAdapter::DOCUMENTED_LANGUAGES);

        $candidates = (new GhidraAdapter)->candidateTargets();
        $ids = array_column($candidates, 'id');
        $this->assertContains('mips32', $ids);
        $this->assertNotContains('arm32', $ids);
        foreach ($candidates as $candidate) {
            $this->assertStringContainsString('unverified', $candidate['note']);
        }
    }

    public function test_raw_requires_configuration_structured_is_ready(): void
    {
        $adapter = new GhidraAdapter;
        $rawCaps = $adapter->capabilities(ProjectArtifact::FORMAT_RAW, 'arm32');
        $this->assertSame(Capability::CONFIGURATION_REQUIRED, $rawCaps[Capability::DISASSEMBLY]);
        $this->assertSame(Capability::SUPPORTED, $rawCaps[Capability::DETECTION]);

        $elfCaps = $adapter->capabilities(ProjectArtifact::FORMAT_ELF, 'aarch64');
        $this->assertTrue(Capability::requestable($elfCaps));
    }

    public function test_arm_and_thumb_are_distinct_languages(): void
    {
        // The v8T Thumb-default variant is a different language, not
        // an alias: decoding Thumb code as ARM produces garbage.
        $this->assertNotSame(
            GhidraAdapter::VERIFIED_LANGUAGES['arm32'],
            GhidraAdapter::VERIFIED_LANGUAGES['arm32-thumb']
        );
        $this->assertStringEndsWith('v8T', GhidraAdapter::VERIFIED_LANGUAGES['arm32-thumb']);
    }
}
