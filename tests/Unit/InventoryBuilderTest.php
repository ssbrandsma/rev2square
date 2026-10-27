<?php

namespace Tests\Unit;

use App\Services\Revival\InventoryBuilder;
use PHPUnit\Framework\TestCase;

class InventoryBuilderTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return array_merge([
            'max_depth' => 6,
            'max_inventory_entries' => 5000,
            'max_download_file_bytes' => 1000000,
            'max_extraction_bytes' => 100000,
            'exclude_dirs' => [],
        ], $overrides);
    }

    public function test_inventories_every_blob_with_classification(): void
    {
        $tree = [
            ['path' => 'tools/app.bin', 'type' => 'blob', 'size' => 10, 'sha' => str_repeat('b', 40)],
            ['path' => 'src/main.c', 'type' => 'blob', 'size' => 100, 'sha' => str_repeat('c', 40)],
            ['path' => 'src', 'type' => 'tree', 'size' => 0],
            ['path' => 'README.md', 'type' => 'blob', 'size' => 50],
            ['path' => '', 'type' => 'blob', 'size' => 5],
        ];

        $result = InventoryBuilder::build($tree, $this->config());

        $this->assertSame(5, $result['discovered']);
        $this->assertSame(3, $result['blobs']);
        $this->assertFalse($result['capped']);

        // Deterministic path order regardless of API traversal order.
        $this->assertSame(
            ['README.md', 'src/main.c', 'tools/app.bin'],
            array_column($result['entries'], 'path')
        );

        $byPath = collect($result['entries'])->keyBy('path');
        $this->assertTrue($byPath['README.md']['eligible']);
        $this->assertTrue($byPath['README.md']['overview']);
        $this->assertSame('C', $byPath['src/main.c']['language']);
        $this->assertSame('src', $byPath['src/main.c']['subsystem']);
        $this->assertSame(str_repeat('c', 40), $byPath['src/main.c']['blob_sha']);

        // Binaries are inventoried but not eligible for evidence.
        $this->assertFalse($byPath['tools/app.bin']['eligible']);
        $this->assertSame('unsupported format', $byPath['tools/app.bin']['skip_reason']);
    }

    public function test_rejects_unsafe_paths_and_enforces_inventory_cap(): void
    {
        $tree = [
            ['path' => '/etc/passwd', 'type' => 'blob', 'size' => 10],
            ['path' => '../escape.c', 'type' => 'blob', 'size' => 10],
            ['path' => 'b.c', 'type' => 'blob', 'size' => 10],
            ['path' => 'a.c', 'type' => 'blob', 'size' => 10],
        ];

        $result = InventoryBuilder::build($tree, $this->config(['max_inventory_entries' => 3]));

        $this->assertTrue($result['capped']);
        $this->assertSame(1, $result['over_cap']);
        $this->assertSame(
            ['../escape.c', '/etc/passwd', 'a.c'],
            array_column($result['entries'], 'path')
        );
        $this->assertSame('unsafe path', $result['entries'][0]['skip_reason']);
    }
}
