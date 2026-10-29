<?php

namespace Tests\Support;

/**
 * Synthetic binary fixtures, generated in code (provenance: this
 * file). No downloaded, proprietary, or malware-derived bytes.
 * Layouts follow the published structure of each format at a
 * minimal level sufficient to exercise detection and parsing.
 */
final class BinaryFixtures
{
    // ---------- Intel HEX / S-record ----------

    public static function ihex(array $chunks = [[0x0000, 'HELLO-HEX']], ?int $entry = 0x0000): string
    {
        $lines = [];
        foreach ($chunks as [$address, $data]) {
            $payload = array_values(unpack('C*', $data));
            $record = [count($payload), ($address >> 8) & 0xFF, $address & 0xFF, 0x00, ...$payload];
            $record[] = ((~array_sum($record) + 1) & 0xFF);
            $lines[] = ':'.strtoupper(implode('', array_map(fn ($b) => sprintf('%02X', $b), $record)));
        }
        if ($entry !== null) {
            $record = [0x04, 0x00, 0x00, 0x05, ($entry >> 24) & 0xFF, ($entry >> 16) & 0xFF, ($entry >> 8) & 0xFF, $entry & 0xFF];
            $record[] = ((~array_sum($record) + 1) & 0xFF);
            $lines[] = ':'.strtoupper(implode('', array_map(fn ($b) => sprintf('%02X', $b), $record)));
        }
        $lines[] = ':00000001FF';

        return implode("\n", $lines)."\n";
    }

    public static function srec(array $chunks = [[0x0000, 'HELLO-SREC']], ?int $entry = 0x0000): string
    {
        $lines = ['S00600004844521B'];
        foreach ($chunks as [$address, $data]) {
            $payload = array_values(unpack('C*', $data));
            $record = [4 + count($payload) + 1, ($address >> 24) & 0xFF, ($address >> 16) & 0xFF, ($address >> 8) & 0xFF, $address & 0xFF, ...$payload];
            $record[] = ((~array_sum($record)) & 0xFF);
            $lines[] = 'S3'.strtoupper(implode('', array_map(fn ($b) => sprintf('%02X', $b), $record)));
        }
        if ($entry !== null) {
            $record = [5, ($entry >> 24) & 0xFF, ($entry >> 16) & 0xFF, ($entry >> 8) & 0xFF, $entry & 0xFF];
            $record[] = ((~array_sum($record)) & 0xFF);
            $lines[] = 'S7'.strtoupper(implode('', array_map(fn ($b) => sprintf('%02X', $b), $record)));
        }

        return implode("\n", $lines)."\n";
    }

    // ---------- C64 PRG ----------

    /** BASIC launcher (10 SYS 2064) + 6502 payload at $0810. */
    public static function prgBasic(string $payload = "\xA9\x00\x8D\x00\x02\x60"): string
    {
        // Line 10: link(2) num(2) SYS($9E) " 2064" 00
        $line = "\x9E\x20\x32\x30\x36\x34\x00";
        $link = 2 + 2 + 2 + strlen($line) + 2;
        $basic = pack('v', 0x0801)
            .pack('v', $link).pack('v', 10).$line
            ."\x00\x00";
        // Pad so the payload lands at $0810 (decimal 2064).
        $basic .= str_repeat("\x00", 0x0810 - 0x0801 - (strlen($basic) - 2));

        return $basic.$payload;
    }

    /** Machine-language-only PRG at $1000 (no BASIC chain). */
    public static function prgMachineCode(string $payload = "\xA9\x01\x8D\x00\x03\x60"): string
    {
        return pack('v', 0x1000).$payload;
    }

    // ---------- Machine-code snippets ----------

    /** 6502: JSR $1005; RTS-pad; f1005: LDA/STA/RTS. */
    public static function code6502(): string
    {
        return hex2bin('20051000EA') // JSR $1005 ; NOP
            .hex2bin('A9008D000260'); // f1005: LDA #$00 ; STA $0200 ; RTS
    }

    /** Z80: CALL $0008; NOP; f0008: LD A,n; RET. */
    public static function codeZ80(): string
    {
        return hex2bin('CD080000') // CALL $0008 ; NOP
            .hex2bin('3E42C9'); // f0008: LD A,$42 ; RET
    }

    /** 8051: LCALL $0008; NOP; f0008: MOV A,#imm; RET. */
    public static function code8051(): string
    {
        return hex2bin('12000800') // LCALL $0008 ; NOP
            .hex2bin('744222'); // f0008: MOV A,#$42 ; RETI
    }

    // ---------- Windows PE ----------

    /**
     * Minimal PE32+ (or PE32) with one executable section.
     *
     * @param  array{machine?: int, dll?: bool, clr?: bool, imports?: bool, code?: string}  $opts
     */
    public static function pe(array $opts = []): array
    {
        $machine = $opts['machine'] ?? 0x8664;
        $is64 = $machine !== 0x014C;
        $code = $opts['code'] ?? "SYNTH-FIXTURE-PE\x00\xC3\x90\x90";

        $dos = str_repeat("\x00", 64);
        $dos[0] = 'M';
        $dos[1] = 'Z';
        $dos = substr_replace($dos, pack('V', 0x80), 0x3C, 4);

        $numSections = ($opts['imports'] ?? false) || ($opts['clr'] ?? false) ? 2 : 1;
        $optSize = $is64 ? 240 : 224;
        $coff = pack('v', $machine).pack('v', $numSections).pack('V', 0).pack('V', 0).pack('V', 0).pack('v', $optSize).pack('v', ($opts['dll'] ?? false) ? 0x2002 : 0x0002);

        $numDirs = 16;
        $opt = pack('v', $is64 ? 0x20B : 0x10B);
        $opt .= "\x00\x00"; // linker version
        $opt .= pack('V', 0x200).pack('V', 0x200).pack('V', 0); // sizes
        $opt .= pack('V', 0x1000); // entry RVA
        $opt .= pack('V', 0x1000); // base of code
        if ($is64) {
            $opt .= pack('P', 0x140000000); // image base
        } else {
            $opt .= pack('V', 0x2000); // base of data (PE32 only)
            $opt .= pack('V', 0x400000); // image base
        }
        $opt .= pack('V', 0x1000).pack('V', 0x200); // align
        $opt .= pack('v', 6).pack('v', 0).pack('v', 0).pack('v', 0).pack('v', 6).pack('v', 0).pack('V', 0); // versions
        $opt .= pack('V', 0x3000).pack('V', 0x200); // image/headers size
        $opt .= pack('V', 0); // checksum
        $opt .= pack('v', 3).pack('v', 0); // subsystem console, dll chars
        $opt .= $is64 ? pack('P', 0x100000).pack('P', 0x1000).pack('P', 0x100000).pack('P', 0x1000) : pack('V', 0x100000).pack('V', 0x1000).pack('V', 0x100000).pack('V', 0x1000);
        $opt .= pack('V', 0).pack('V', $numDirs);

        $dirs = str_repeat("\x00", 16 * 8);
        if ($opts['imports'] ?? false) {
            $dirs = substr_replace($dirs, pack('V', 0x2000).pack('V', 40), 1 * 8, 8);
        }
        if ($opts['clr'] ?? false) {
            $dirs = substr_replace($dirs, pack('V', 0x2060).pack('V', 72), 14 * 8, 8);
        }
        $opt .= $dirs;
        $opt = str_pad($opt, $optSize, "\x00");

        $text = '.text'."\x00\x00\x00".pack('V', 0x200).pack('V', 0x1000).pack('V', 0x200).pack('V', 0x200).pack('V', 0).pack('V', 0).pack('v', 0).pack('v', 0).pack('V', 0x60000020);
        $sections = $text;
        if ($numSections === 2) {
            $sections .= '.rdata'."\x00\x00".pack('V', 0x200).pack('V', 0x2000).pack('V', 0x200).pack('V', 0x400).pack('V', 0).pack('V', 0).pack('v', 0).pack('v', 0).pack('V', 0x40000040);
        }

        $file = $dos.str_repeat("\x00", 0x80 - 64)."PE\0\0".$coff.$opt.$sections;
        $file = str_pad($file, 0x200, "\x00");
        $file .= str_pad($code, 0x200, "\x00");

        if ($numSections === 2) {
            $rdata = str_repeat("\x00", 0x200);
            if ($opts['imports'] ?? false) {
                // Import descriptor: ILT rva, 0, 0, name rva, IAT rva.
                $iatOff = $is64 ? 0x38 : 0x30;
                $rdata = substr_replace($rdata, pack('V', 0x2028).pack('V', 0).pack('V', 0).pack('V', 0x2040).pack('V', 0x2000 + $iatOff), 0, 20);
                $rdata = substr_replace($rdata, pack($is64 ? 'P' : 'V', 0x2050).pack($is64 ? 'P' : 'V', 0), 0x28, $is64 ? 16 : 8);
                $rdata = substr_replace($rdata, pack($is64 ? 'P' : 'V', 0x2050).pack($is64 ? 'P' : 'V', 0), $iatOff, $is64 ? 16 : 8);
                $rdata = substr_replace($rdata, "SYNTHETIC32.DLL\x00", 0x40, 16);
                $rdata = substr_replace($rdata, pack('v', 7).'SyntheticFunction'."\x00", 0x50, 21);
            }
            if ($opts['clr'] ?? false) {
                // Minimal CLR header: cb, major, minor, metadata rva/size, flags.
                $rdata = substr_replace($rdata, pack('V', 72).pack('v', 2).pack('v', 5).pack('V', 0x3000).pack('V', 0x100).pack('V', 0x8), 0x60, 24);
            }
            $file .= $rdata;
        }

        return ['bytes' => $file, 'entry_rva' => 0x1000, 'image_base' => $is64 ? 0x140000000 : 0x400000];
    }

    // ---------- Mach-O ----------

    public static function machoThin(int $cputype = 0x01000007, string $code = "\x90\x90\xC3"): string
    {
        $header = pack('V', 0xFEEDFACF).pack('V', $cputype).pack('V', 3).pack('V', 2).pack('V', 2).pack('V', 152 + 32).pack('V', 0).pack('V', 0);
        $seg = pack('V', 0x19).pack('V', 152).'__TEXT'."\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
            .pack('P', 0x1000).pack('P', 0x1000).pack('P', 0x200).pack('P', 0x1000)
            .pack('V', 7).pack('V', 7).pack('V', 1).pack('V', 0);
        $sect = '__text'."\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00".'__TEXT'."\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
            .pack('P', 0x1000).pack('P', strlen($code)).pack('V', 0x200).pack('V', 4).pack('V', 0).pack('V', 0).pack('V', 0).pack('V', 0).pack('V', 0).pack('V', 0);
        $dylib = pack('V', 0x0C).pack('V', 32).pack('V', 24).pack('V', 0).pack('V', 0).pack('V', 0).'/syn/libX'."\x00\x00\x00\x00";

        return str_pad($header.$seg.$sect.$dylib, 0x200, "\x00").$code;
    }

    public static function machoFat(): string
    {
        $thin = self::machoThin();
        $fat = pack('N', 0xCAFEBABE).pack('N', 1)
            .pack('N', 0x01000007).pack('N', 3).pack('N', 0x100).pack('N', strlen($thin)).pack('N', 12);

        return str_pad($fat, 0x100, "\x00").$thin;
    }

    // ---------- DEX ----------

    /**
     * Minimal DEX with configurable strings/classes/methods.
     *
     * @param  list<string>  $strings
     * @param  list<string>  $classes  type descriptors (must exist in $strings)
     * @param  list<array{int, int}>  $methods  [classStringIdx, nameStringIdx]
     */
    public static function dex(array $strings = ['Lcom/synth/App;', 'doThing'], array $classes = ['Lcom/synth/App;'], array $methods = [[0, 1]]): string
    {
        $stringIds = '';
        $data = '';
        $dataOff = 0x70 + count($strings) * 4 + count(array_unique($strings)) * 4 + count($methods) * 8 + count($classes) * 32;
        $stringOffsets = [];
        foreach ($strings as $string) {
            $stringOffsets[] = $dataOff + strlen($data);
            $data .= self::uleb128(strlen($string)).$string."\x00";
        }

        $stringIdsOff = 0x70;
        foreach ($stringOffsets as $off) {
            $stringIds .= pack('V', $off);
        }

        $typeIds = '';
        $typeIndex = [];
        foreach (array_unique($strings) as $string) {
            $typeIndex[$string] = count($typeIndex);
            $typeIds .= pack('V', array_search($string, $strings, true));
        }
        $typeIdsOff = $stringIdsOff + strlen($stringIds);

        $methodIds = '';
        foreach ($methods as [$classIdx, $nameIdx]) {
            $methodIds .= pack('v', $typeIndex[$classes[$classIdx]]).pack('v', 0).pack('V', $nameIdx);
        }
        $methodIdsOff = $typeIdsOff + strlen($typeIds);

        $classDefs = '';
        foreach ($classes as $class) {
            $classDefs .= pack('V', $typeIndex[$class]).pack('V', 1).pack('V', 0xFFFFFFFF).pack('V', 0).pack('V', 0xFFFFFFFF).pack('V', 0).pack('V', 0).pack('V', 0);
        }
        $classDefsOff = $methodIdsOff + strlen($methodIds);

        $fileSize = $classDefsOff + strlen($classDefs) + strlen($data);
        $header = "dex\n035\0".pack('V', 0).str_repeat("\x00", 20).pack('V', $fileSize).pack('V', 0x70)
            .pack('V', 0x12345678).pack('V', 0).pack('V', 0).pack('V', 0)
            .pack('V', count($strings)).pack('V', $stringIdsOff)
            .pack('V', count($typeIndex)).pack('V', $typeIdsOff)
            .pack('V', 0).pack('V', 0).pack('V', 0).pack('V', 0)
            .pack('V', count($methods)).pack('V', $methodIdsOff)
            .pack('V', count($classes)).pack('V', $classDefsOff)
            .pack('V', strlen($data)).pack('V', $classDefsOff + strlen($classDefs));

        return $header.$stringIds.$typeIds.$methodIds.$classDefs.$data;
    }

    private static function uleb128(int $value): string
    {
        $out = '';
        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            $out .= chr($byte | ($value !== 0 ? 0x80 : 0));
        } while ($value !== 0);

        return $out;
    }

    // ---------- Binary Android manifest (string pool only) ----------

    /** @param  list<string>  $strings */
    public static function axml(array $strings = ['com.synthetic.app', 'android.permission.INTERNET']): string
    {
        $pool = '';
        $offsets = [];
        $at = 0;
        foreach ($strings as $string) {
            $offsets[] = $at;
            // UTF-8 form: char-len, byte-len, bytes, NUL.
            $item = chr(strlen($string)).chr(strlen($string)).$string."\x00";
            $pool .= $item;
            $at += strlen($item);
        }

        $chunk = pack('v', 0x0001).pack('v', 28);
        $chunkSize = 28 + count($strings) * 4 + strlen($pool);
        $chunk .= pack('V', $chunkSize).pack('V', count($strings)).pack('V', 0).pack('V', 1 << 8).pack('V', 28 + count($strings) * 4).pack('V', 0);
        foreach ($offsets as $off) {
            $chunk .= pack('V', $off);
        }
        $chunk .= $pool;

        $fileSize = 8 + strlen($chunk);
        $header = pack('v', 0x0003).pack('v', 8).pack('V', $fileSize);

        return $header.$chunk;
    }

    // ---------- APK ----------

    /**
     * @param  array{dexes?: int, so?: bool, manifest?: string, split?: bool}  $opts
     */
    public static function apk(array $opts = []): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'apk');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE | \ZipArchive::CREATE);
        $zip->addFromString('AndroidManifest.xml', $opts['manifest'] ?? self::axml());

        $dexes = $opts['dexes'] ?? 1;
        for ($i = 0; $i < $dexes; $i++) {
            $name = $i === 0 ? 'classes.dex' : 'classes'.($i + 1).'.dex';
            $zip->addFromString($name, self::dex(
                ['Lcom/synth/App'.$i.';', 'doThing'.$i],
                ['Lcom/synth/App'.$i.';'],
                [[0, 1]],
            ));
        }

        if ($opts['so'] ?? false) {
            $elf = (string) file_get_contents(__DIR__.'/../Fixtures/Artifacts/files/elf64-x86_64.elf');
            $zip->addFromString('lib/arm64-v8a/libsynth.so', $elf);
        }

        $zip->addFromString('resources.arsc', "\x02\x00\x0C\x00DUMMY-ARSC");
        $zip->addFromString('assets/note.txt', 'synthetic apk fixture');
        if ($opts['split'] ?? false) {
            $zip->addFromString('BUNDLE-METADATA/com.android.tools.build.bundletool/version.pb', 'x');
        }
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        return $bytes;
    }

    // ---------- Java class ----------

    public static function classFile(string $class = 'Synth', string $super = 'java/lang/Object'): string
    {
        $pool = chr(1).pack('n', strlen($class)).$class
            .chr(7).pack('n', 1)
            .chr(1).pack('n', strlen($super)).$super
            .chr(7).pack('n', 3);

        return "\xCA\xFE\xBA\xBE".pack('n', 0).pack('n', 52).pack('n', 5).$pool
            .pack('n', 2).pack('n', 4).pack('n', 0).pack('n', 0).pack('n', 0).pack('n', 0);
    }

    // ---------- Generic ZIP ----------

    public static function zip(array $members = ['docs/readme.txt' => 'hello']): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE | \ZipArchive::CREATE);
        foreach ($members as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        return $bytes;
    }
}
