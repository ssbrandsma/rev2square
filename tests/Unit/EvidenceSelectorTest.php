<?php

namespace Tests\Unit;

use App\Models\EvidenceSource;
use App\Services\Revival\EvidenceSelector;
use PHPUnit\Framework\TestCase;

class EvidenceSelectorTest extends TestCase
{
    private function row(int $id, string $path, string $content): EvidenceSource
    {
        $row = new EvidenceSource;
        $row->id = $id;
        $row->path = $path;
        $row->url = 'https://example.test/'.$path;
        $row->content = $content;

        return $row;
    }

    public function test_prioritizes_readme_research_and_docs(): void
    {
        $sources = collect([
            $this->row(1, 'tools/app.py', 'code'),
            $this->row(2, 'docs/guide.md', 'guide'),
            $this->row(3, 'research/notes.md', 'notes'),
            $this->row(4, 'README.md', 'readme'),
        ]);

        $result = EvidenceSelector::select($sources, 100000);

        $this->assertSame(
            ['README.md', 'research/notes.md', 'docs/guide.md', 'tools/app.py'],
            array_column($result['included'], 'path')
        );
        $this->assertSame([], $result['excluded']);
    }

    public function test_scored_rows_use_relevance_order_with_provenance(): void
    {
        $driver = $this->row(1, 'tools/driver.c', 'code');
        $driver->relevance_score = 120;
        $driver->is_partial = true;
        $driver->line_ranges = [['start' => 1, 'end' => 30]];
        $driver->original_lines = 400;
        $driver->language = 'C';

        $util = $this->row(2, 'app/util.py', 'code');
        $util->relevance_score = 40;

        $readme = $this->row(3, 'README.md', 'readme');
        $readme->relevance_score = 100;

        $result = EvidenceSelector::select(collect([$util, $driver, $readme]), 100000);

        // Overview first, then score — never path order.
        $this->assertSame(
            ['README.md', 'tools/driver.c', 'app/util.py'],
            array_column($result['included'], 'path')
        );

        $driverItem = $result['included'][1];
        $this->assertTrue($driverItem['is_partial']);
        $this->assertSame([['start' => 1, 'end' => 30]], $driverItem['line_ranges']);
        $this->assertSame(400, $driverItem['original_lines']);
        $this->assertSame('C', $driverItem['language']);
        $this->assertSame(120, $driverItem['relevance_score']);
    }

    public function test_records_budget_exclusions_with_reasons(): void
    {
        $sources = collect([
            $this->row(1, 'README.md', str_repeat('a', 400)),
            $this->row(2, 'docs/guide.md', str_repeat('b', 400)),
        ]);

        // 400 chars ≈ 100 tokens each; budget fits one file.
        $result = EvidenceSelector::select($sources, 150);

        $this->assertSame(['README.md'], array_column($result['included'], 'path'));
        $this->assertSame([
            ['source_id' => 2, 'path' => 'docs/guide.md', 'reason' => 'over analysis token budget'],
        ], $result['excluded']);
        $this->assertSame(100, $result['approx_tokens']);
    }
}
