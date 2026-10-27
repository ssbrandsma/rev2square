<?php

namespace Tests\Unit;

use App\Services\Revival\FileExtractor;
use PHPUnit\Framework\TestCase;

class FileExtractorTest extends TestCase
{
    public function test_small_files_pass_through_complete(): void
    {
        $content = "import os\n\nprint('hi')\n";

        $result = FileExtractor::extract('app.py', $content, 100000, 20000);

        $this->assertTrue($result['complete']);
        $this->assertSame($content, $result['content']);
        $this->assertSame([['start' => 1, 'end' => 3]], $result['ranges']);
        $this->assertSame(strlen($content), $result['original_bytes']);
        $this->assertSame(3, $result['original_lines']);
        $this->assertNull($result['note']);
    }

    public function test_large_python_file_yields_ranged_excerpt(): void
    {
        $lines = ['import usb.core', '"""Device driver."""', ''];
        for ($i = 1; $i <= 300; $i++) {
            $lines[] = "FILLER_{$i} = '{$i}'";
        }
        $lines[] = 'def open_device(vendor_id, product_id):';
        $lines[] = '    handle = usb.core.find(idVendor=vendor_id)';
        $lines[] = '    return handle';
        for ($i = 301; $i <= 600; $i++) {
            $lines[] = "TAIL_{$i} = '{$i}'";
        }
        $content = implode("\n", $lines)."\n";

        $result = FileExtractor::extract('app/driver.py', $content, 3000, 20000);

        $this->assertFalse($result['complete']);
        $this->assertLessThanOrEqual(3000 + 500, strlen($result['content'])); // + omission markers
        $this->assertStringContainsString('import usb.core', $result['content']);
        $this->assertStringContainsString('def open_device', $result['content']);
        $this->assertStringContainsString('omitted lines', $result['content']);

        // Ranges are 1-based, ordered, and cover the symbol definition.
        $this->assertNotEmpty($result['ranges']);
        $starts = array_column($result['ranges'], 'start');
        $sorted = $starts;
        sort($sorted);
        $this->assertSame($sorted, $starts);

        $defLine = array_search('def open_device(vendor_id, product_id):', $lines) + 1;
        $covered = false;
        foreach ($result['ranges'] as $range) {
            $this->assertLessThanOrEqual($range['end'], $range['start']);
            if ($range['start'] <= $defLine && $defLine <= $range['end']) {
                $covered = true;
            }
        }
        $this->assertTrue($covered, 'symbol definition line must be inside an extracted range');
        $this->assertSame(count($lines), $result['original_lines']);
        $this->assertNotNull($result['note']);
    }

    public function test_large_c_file_keeps_includes_and_functions(): void
    {
        $lines = ['#include <libusb.h>', '#define VENDOR_ID 0x1234', ''];
        for ($i = 1; $i <= 400; $i++) {
            $lines[] = "/* padding line {$i} to grow the file beyond the budget */";
        }
        $lines[] = 'int remote_send(int handle, const uint8_t *payload)';
        $lines[] = '{';
        $lines[] = '    return libusb_bulk_transfer(handle, payload);';
        $lines[] = '}';
        $content = implode("\n", $lines)."\n";

        $result = FileExtractor::extract('tools/remote.c', $content, 2500, 20000);

        $this->assertFalse($result['complete']);
        $this->assertStringContainsString('#include <libusb.h>', $result['content']);
        $this->assertStringContainsString('int remote_send(int handle', $result['content']);
    }

    public function test_minified_content_is_marked_truncated(): void
    {
        $content = str_repeat('var a=1;', 5000); // single line, 45KB

        $result = FileExtractor::extract('ui/bundle.js', $content, 10000, 20000);

        $this->assertFalse($result['complete']);
        $this->assertSame([['start' => 1, 'end' => 1]], $result['ranges']);
        $this->assertStringContainsString('truncated', $result['content']);
        $this->assertSame(40000, $result['original_bytes']);
    }

    public function test_extraction_is_deterministic(): void
    {
        $lines = ['import os'];
        for ($i = 1; $i <= 500; $i++) {
            $lines[] = "x_{$i} = {$i}";
        }
        $lines[] = 'def entry():';
        $lines[] = '    pass';
        $content = implode("\n", $lines);

        $first = FileExtractor::extract('a.py', $content, 2000, 20000);
        $second = FileExtractor::extract('a.py', $content, 2000, 20000);

        $this->assertSame($first, $second);
    }

    public function test_handles_empty_content(): void
    {
        $result = FileExtractor::extract('empty.txt', '', 100000, 20000);

        $this->assertTrue($result['complete']);
        $this->assertSame([], $result['ranges']);
        $this->assertSame(0, $result['original_lines']);
    }
}
