<?php

namespace App\Services\Revival;

/**
 * Mutable per-import accumulation state. Carries the growing file,
 * skip, and warning lists plus budget counters between the import
 * stages so import() stays a readable orchestration.
 */
class ImportProgress
{
    /** @var list<array<string, mixed>> */
    public array $files = [];

    /** @var list<array{path: string, reason: string}> */
    public array $skipped = [];

    /** @var list<string> */
    public array $warnings = [];

    public int $downloaded = 0;

    public int $approxTokens = 0;
}
