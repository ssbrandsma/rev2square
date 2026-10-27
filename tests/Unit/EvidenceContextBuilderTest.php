<?php

namespace Tests\Unit;

use App\Models\Repository;
use App\Services\Revival\EvidenceContextBuilder;
use PHPUnit\Framework\TestCase;

class EvidenceContextBuilderTest extends TestCase
{
    public function test_builds_inventory_coverage_and_partial_block(): void
    {
        $repository = new Repository;
        $repository->last_commit_sha = str_repeat('a', 40);
        $repository->last_import_summary = [
            'inventory' => [
                'blobs' => 175, 'eligible' => 60, 'downloaded' => 40,
                'stored' => 38, 'partial' => 4, 'capped' => false, 'over_cap' => 0,
            ],
            'coverage' => ['app' => 20, 'tools' => 12, '(root)' => 6],
            'missing_relevant' => [
                ['path' => 'app/extra.py', 'reason' => 'file count limit reached', 'score' => 70],
            ],
        ];

        $included = [
            ['path' => 'README.md', 'is_partial' => false],
            [
                'path' => 'app/main.py',
                'is_partial' => true,
                'line_ranges' => [['start' => 1, 'end' => 30], ['start' => 400, 'end' => 410]],
                'original_lines' => 900,
            ],
        ];
        $excluded = [['path' => 'docs/extra.md']];

        $context = EvidenceContextBuilder::forRepository($repository, $included, $excluded);

        $this->assertStringContainsString('Discovered 175 files (60 eligible', $context);
        $this->assertStringContainsString('including 4 partial excerpts', $context);
        $this->assertStringContainsString('app (20)', $context);
        $this->assertStringContainsString('app/main.py: lines L1-L30, L400-L410 of 900', $context);
        $this->assertStringContainsString('app/extra.py (file count limit reached)', $context);
        $this->assertStringContainsString('docs/extra.md', $context);
        $this->assertStringContainsString('NOT received', $context);
    }

    public function test_returns_empty_string_for_legacy_imports(): void
    {
        $repository = new Repository;
        $repository->last_import_summary = ['files' => 1, 'skipped' => []];

        $this->assertSame('', EvidenceContextBuilder::forRepository($repository, [], []));
    }
}
