<?php

namespace Tests\Unit;

use App\Services\Revival\EvidencePlanner;
use PHPUnit\Framework\TestCase;

class EvidencePlannerTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return array_merge([
            'max_files' => 50,
            'max_download_bytes' => 2000000,
            'max_evidence_bytes' => 500000,
            'max_extraction_bytes' => 100000,
            'token_budget' => 100000,
        ], $overrides);
    }

    private function item(string $path, int $score, string $subsystem, bool $overview = false, int $size = 1000): array
    {
        return ['path' => $path, 'size_bytes' => $size, 'score' => $score, 'subsystem' => $subsystem, 'overview' => $overview];
    }

    public function test_orders_overview_first_then_score(): void
    {
        $items = [
            $this->item('app/z.py', 90, 'app'),
            $this->item('docs/guide.md', 40, 'docs'),
            $this->item('README.md', 100, '(root)', true),
            $this->item('app/a.py', 90, 'app'),
        ];

        $result = EvidencePlanner::plan($items, $this->config());

        $this->assertSame(
            ['README.md', 'app/a.py', 'app/z.py', 'docs/guide.md'],
            array_column($result['queue'], 'path')
        );
        $this->assertSame([], $result['skipped']);
    }

    public function test_ensures_subsystem_coverage(): void
    {
        $items = [
            $this->item('app/a1.py', 90, 'app'),
            $this->item('app/a2.py', 89, 'app'),
            $this->item('app/a3.py', 88, 'app'),
            $this->item('tools/driver.c', 50, 'tools'),
            $this->item('fw/hal.c', 49, 'fw'),
        ];

        // Dominance cap = 2: the third app file must wait until tools/fw are covered.
        $result = EvidencePlanner::plan($items, $this->config(['max_files' => 4]));

        $this->assertSame(
            ['app/a1.py', 'app/a2.py', 'tools/driver.c', 'fw/hal.c'],
            array_column($result['queue'], 'path')
        );
        $this->assertSame(
            [['path' => 'app/a3.py', 'reason' => 'file count limit reached']],
            $result['skipped']
        );
    }

    public function test_enforces_download_evidence_and_token_budgets(): void
    {
        $items = [
            $this->item('a.py', 90, 'app', false, 4000),
            $this->item('b.py', 80, 'app', false, 4000),
        ];

        $result = EvidencePlanner::plan($items, $this->config(['max_download_bytes' => 5000]));
        $this->assertSame(['a.py'], array_column($result['queue'], 'path'));
        $this->assertSame('download budget exhausted', $result['skipped'][0]['reason']);

        $result = EvidencePlanner::plan($items, $this->config(['max_evidence_bytes' => 5000]));
        $this->assertSame(['a.py'], array_column($result['queue'], 'path'));
        $this->assertSame('evidence byte budget exhausted', $result['skipped'][0]['reason']);

        // 4000 bytes ≈ 1000 tokens each; budget fits one file.
        $result = EvidencePlanner::plan($items, $this->config(['token_budget' => 1500]));
        $this->assertSame(['a.py'], array_column($result['queue'], 'path'));
        $this->assertSame('evidence token budget exhausted', $result['skipped'][0]['reason']);

        $result = EvidencePlanner::plan($items, $this->config(['max_files' => 1]));
        $this->assertSame(['a.py'], array_column($result['queue'], 'path'));
        $this->assertSame('file count limit reached', $result['skipped'][0]['reason']);
    }

    public function test_plan_is_deterministic_regardless_of_input_order(): void
    {
        $items = [
            $this->item('app/z.py', 90, 'app'),
            $this->item('README.md', 100, '(root)', true),
            $this->item('tools/d.c', 50, 'tools'),
            $this->item('app/a.py', 90, 'app'),
        ];

        $forward = EvidencePlanner::plan($items, $this->config());
        $reversed = EvidencePlanner::plan(array_reverse($items), $this->config());

        $this->assertSame($forward, $reversed);
    }
}
