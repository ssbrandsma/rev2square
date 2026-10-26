<?php

namespace Tests\Unit;

use App\Services\Revival\FileSelector;
use PHPUnit\Framework\TestCase;

class EvidenceManifestTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return array_merge([
            'max_files' => 50,
            'max_file_bytes' => 100000,
            'max_total_bytes' => 500000,
            'max_depth' => 6,
            'include_extensions' => ['md', 'txt', 'py', 'json'],
            'include_basenames' => ['license', 'readme', 'makefile'],
            'exclude_dirs' => ['node_modules', 'vendor', '.git', 'build'],
        ], $overrides);
    }

    public function test_ranks_readme_before_docs_before_source(): void
    {
        $tree = [
            ['path' => 'tools/app.py', 'type' => 'blob', 'size' => 100],
            ['path' => 'docs/guide.md', 'type' => 'blob', 'size' => 100],
            ['path' => 'README.md', 'type' => 'blob', 'size' => 100],
        ];

        $result = FileSelector::select($tree, $this->config());

        $this->assertSame(
            ['README.md', 'docs/guide.md', 'tools/app.py'],
            array_column($result['selected'], 'path')
        );
        $this->assertSame([], $result['skipped']);
    }

    public function test_skips_trees_empty_paths_and_excluded_dirs(): void
    {
        $tree = [
            ['path' => 'docs', 'type' => 'tree', 'size' => 0],
            ['path' => '', 'type' => 'blob', 'size' => 10],
            ['path' => 'vendor/lib.md', 'type' => 'blob', 'size' => 10],
            ['path' => 'README.md', 'type' => 'blob', 'size' => 10],
        ];

        $result = FileSelector::select($tree, $this->config());

        $this->assertSame(['README.md'], array_column($result['selected'], 'path'));
        $this->assertSame(
            [['path' => 'vendor/lib.md', 'reason' => 'excluded directory']],
            $result['skipped']
        );
    }

    public function test_skips_unallowlisted_extensions_and_oversized_files(): void
    {
        $tree = [
            ['path' => 'app/viewer.html', 'type' => 'blob', 'size' => 10],
            ['path' => 'big/data.md', 'type' => 'blob', 'size' => 200000],
            ['path' => 'LICENSE', 'type' => 'blob', 'size' => 10],
        ];

        $result = FileSelector::select($tree, $this->config());

        $this->assertSame(['LICENSE'], array_column($result['selected'], 'path'));
        $this->assertEqualsCanonicalizing(
            [
                ['path' => 'app/viewer.html', 'reason' => 'extension not allowlisted'],
                ['path' => 'big/data.md', 'reason' => 'exceeds file-size limit'],
            ],
            $result['skipped']
        );
    }

    public function test_enforces_file_count_and_byte_budgets(): void
    {
        $tree = [
            ['path' => 'a.md', 'type' => 'blob', 'size' => 60],
            ['path' => 'b.md', 'type' => 'blob', 'size' => 60],
            ['path' => 'c.md', 'type' => 'blob', 'size' => 60],
        ];

        $result = FileSelector::select($tree, $this->config(['max_files' => 2]));

        $this->assertCount(2, $result['selected']);
        $this->assertSame('over file-count budget', $result['skipped'][0]['reason']);

        $result = FileSelector::select($tree, $this->config(['max_total_bytes' => 100]));

        $this->assertCount(1, $result['selected']);
        $this->assertSame('over total-byte budget', $result['skipped'][0]['reason']);
    }

    public function test_allows_suffixed_license_and_changelog_basenames(): void
    {
        $tree = [
            ['path' => 'LICENSE-DOCS', 'type' => 'blob', 'size' => 10],
            ['path' => 'README_2024', 'type' => 'blob', 'size' => 10],
            ['path' => 'LICENSEE.md', 'type' => 'blob', 'size' => 10],
        ];

        $result = FileSelector::select($tree, $this->config());

        // LICENSEE.md is selected by extension, not by the license prefix rule.
        $this->assertEqualsCanonicalizing(
            ['LICENSE-DOCS', 'README_2024', 'LICENSEE.md'],
            array_column($result['selected'], 'path')
        );
    }

    public function test_enforces_depth_limit(): void
    {
        $tree = [
            ['path' => 'a/b/c/d/e/f/g.md', 'type' => 'blob', 'size' => 10],
        ];

        $result = FileSelector::select($tree, $this->config(['max_depth' => 6]));

        $this->assertSame([], $result['selected']);
        $this->assertSame('exceeds depth limit', $result['skipped'][0]['reason']);
    }
}
