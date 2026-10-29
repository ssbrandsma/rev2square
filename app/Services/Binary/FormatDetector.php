<?php

namespace App\Services\Binary;

use App\Models\ProjectArtifact;

/**
 * Content-based format detection. Magic bytes and structural probes
 * decide; the filename is a weak hint recorded honestly in the
 * evidence list, never the deciding factor on its own (except for
 * formats like C64 PRG that have no magic, where the hint is
 * explicitly labelled as such).
 *
 * @return array{format: string, evidence: list<array{claim: string, detail: string}>, warnings: list<string>}
 */
final class FormatDetector
{
    public static function detect(string $bytes, ?string $filename = null): array
    {
        $reader = new ByteReader($bytes);
        $magic4 = $reader->magic(0, 4) ?? '';
        $ext = strtolower((string) pathinfo((string) $filename, PATHINFO_EXTENSION));

        if ($magic4 === "\x7fELF") {
            return self::report(ProjectArtifact::FORMAT_ELF, [
                ['magic', '4-byte magic 7F 45 4C 46 at file offset 0'],
            ]);
        }

        if ($magic4 === "\xd4\xc3\xb2\xa1" || $magic4 === "\xa1\xb2\xc3\xd4"
            || $magic4 === "\x4d\x3c\xb2\xa1" || $magic4 === "\xa1\xb2\x3c\x4d") {
            return self::report(ProjectArtifact::FORMAT_PCAP, [
                ['magic', 'libpcap global-header magic '.bin2hex($magic4).' at file offset 0'],
            ]);
        }

        if ($magic4 === "\x0a\x0d\x0d\x0a") {
            return self::report(ProjectArtifact::FORMAT_PCAPNG, [
                ['magic', 'PCAPNG section-header magic 0A 0D 0D 0A at file offset 0'],
            ]);
        }

        if (substr($bytes, 0, 2) === 'MZ') {
            $evidence = [['magic', 'DOS stub magic "MZ" at file offset 0']];
            $lfanew = $reader->u32le(0x3C);
            if ($lfanew !== null && $reader->magic($lfanew, 4) === "PE\0\0") {
                $evidence[] = ['pe_signature', sprintf('PE signature at e_lfanew offset 0x%X', $lfanew)];

                return self::report(ProjectArtifact::FORMAT_PE, $evidence);
            }
            $evidence[] = ['pe_signature_missing', 'No PE signature at the e_lfanew offset; likely a bare DOS stub or truncated header'];

            return self::report(ProjectArtifact::FORMAT_UNKNOWN, $evidence, ['mz_without_pe_signature']);
        }

        if (in_array($magic4, ["\xFE\xED\xFA\xCE", "\xFE\xED\xFA\xCF", "\xCE\xFA\xED\xFE", "\xCF\xFA\xED\xFE", "\xBE\xBA\xFE\xCA"], true)) {
            return self::report(ProjectArtifact::FORMAT_MACHO, [
                ['magic', 'Mach-O magic '.bin2hex($magic4).' at file offset 0'],
            ]);
        }

        // CAFEBABE is ambiguous: Mach-O FAT_MAGIC and Java class share
        // it. A FAT header carries a small arch count plus valid arch
        // entries; a class file carries minor/major version numbers.
        if ($magic4 === "\xCA\xFE\xBA\xBE") {
            return self::disambiguateCafeBabe($reader);
        }

        if (substr($bytes, 0, 8) === "dex\n035\0" || substr($bytes, 0, 8) === "dex\n036\0"
            || substr($bytes, 0, 8) === "dex\n037\0" || substr($bytes, 0, 8) === "dex\n038\0"
            || substr($bytes, 0, 8) === "dex\n039\0") {
            return self::report(ProjectArtifact::FORMAT_DEX, [
                ['magic', 'DEX magic "'.substr($bytes, 0, 7).'…" at file offset 0'],
            ]);
        }

        if ($magic4 === "PK\x03\x04" || $magic4 === "PK\x05\x06" || $magic4 === "PK\x07\x08") {
            return self::zipKind($bytes, $ext);
        }

        if (str_starts_with($bytes, ':')) {
            $probe = HexRecords::probeIntelHex($bytes);
            if ($probe['ok']) {
                return self::report(ProjectArtifact::FORMAT_IHEX, [
                    ['record_structure', $probe['detail']],
                ]);
            }

            return self::report(ProjectArtifact::FORMAT_UNKNOWN, [
                ['colon_start', 'File starts with ":" but records do not parse as Intel HEX'],
            ], ['not_intel_hex']);
        }

        if (preg_match('/\AS[0-9]/', $bytes) === 1) {
            $probe = HexRecords::probeSrec($bytes);
            if ($probe['ok']) {
                return self::report(ProjectArtifact::FORMAT_SREC, [
                    ['record_structure', $probe['detail']],
                ]);
            }

            return self::report(ProjectArtifact::FORMAT_UNKNOWN, [
                ['srec_start', 'File starts with an S-record sigil but records do not parse as Motorola S-record'],
            ], ['not_srec']);
        }

        $prg = PrgProbe::probe($bytes, $ext);
        if ($prg !== null) {
            return self::report(ProjectArtifact::FORMAT_PRG, $prg);
        }

        return self::report(ProjectArtifact::FORMAT_UNKNOWN, [
            ['no_recognized_magic', 'First bytes '.bin2hex(substr($bytes, 0, min(8, strlen($bytes)))).' match no supported container or executable magic'],
        ]);
    }

    /** @return array{format: string, evidence: list<array{claim: string, detail: string}>, warnings: list<string>} */
    private static function disambiguateCafeBabe(ByteReader $reader): array
    {
        $count = $reader->u32be(4);
        if ($count !== null && $count >= 1 && $count <= 32) {
            $cputype = $reader->u32be(8);
            $offset = $reader->u32be(12);
            $size = $reader->u32be(16);
            if ($cputype !== null && $offset !== null && $size !== null
                && $offset + $size <= $reader->length()
                && in_array($cputype, [7, 12, 18, 0x01000007, 0x0100000C], true)) {
                return self::report(ProjectArtifact::FORMAT_MACHO, [
                    ['magic', 'CAFEBABE with a valid FAT arch entry (cputype '.$cputype.', '.$count.' arch(es)) at file offset 0'],
                ]);
            }
        }

        $minor = $reader->u16be(4);
        $major = $reader->u16be(6);
        if ($major !== null && $major >= 45 && $major <= 75) {
            return self::report(ProjectArtifact::FORMAT_CLASS, [
                ['magic', 'CAFEBABE with Java class version '.$major.'.'.$minor.' at file offset 0'],
            ]);
        }

        return self::report(ProjectArtifact::FORMAT_UNKNOWN, [
            ['ambiguous_cafebabe', 'CAFEBABE matches neither a valid Mach-O FAT header nor a plausible Java class version'],
        ], ['ambiguous_cafebabe']);
    }

    /** Backwards-compatible format-only detection. */
    public static function format(string $bytes, ?string $filename = null): string
    {
        return self::detect($bytes, $filename)['format'];
    }

    /** @return array{format: string, evidence: list<array{claim: string, detail: string}>, warnings: list<string>} */
    private static function zipKind(string $bytes, string $ext): array
    {
        $cd = ZipReader::centralDirectory($bytes, 2000);
        if (! ($cd['ok'] ?? false)) {
            return self::report(ProjectArtifact::FORMAT_UNKNOWN, [
                ['zip_signature', 'PK signature present but the central directory does not parse ('.($cd['error'] ?? 'unknown').')'],
            ], ['zip_cd_unreadable']);
        }

        $names = array_column($cd['entries'] ?? [], 'name');
        $has = fn (string $needle): bool => in_array($needle, $names, true);
        $anyPrefix = function (string $prefix) use ($names): bool {
            foreach ($names as $name) {
                if (str_starts_with($name, $prefix)) {
                    return true;
                }
            }

            return false;
        };

        if ($has('AndroidManifest.xml') && $anyPrefix('classes')) {
            $dexes = array_values(array_filter($names, fn ($n) => (bool) preg_match('#^classes\d*\.dex$#', $n)));
            $evidence = [
                ['zip_container', 'Valid ZIP central directory with '.count($names).' entries'],
                ['android_manifest', 'AndroidManifest.xml present at archive root'],
                ['dex_members', count($dexes).' DEX member(s): '.implode(', ', array_slice($dexes, 0, 5))],
            ];
            $sos = array_values(array_filter($names, fn ($n) => str_starts_with($n, 'lib/') && str_ends_with($n, '.so')));
            if ($sos !== []) {
                $evidence[] = ['native_libraries', count($sos).' native librar(y/ies) under lib/: '.implode(', ', array_slice($sos, 0, 5))];
            }

            return self::report(ProjectArtifact::FORMAT_APK, $evidence);
        }

        $kind = 'generic ZIP';
        if ($has('BundleConfig.pb') || $anyPrefix('base/')) {
            $kind = 'Android App Bundle (.aab layout)';
        } elseif ($anyPrefix('META-INF/') && $anyPrefix('com/')) {
            $kind = 'JAR-style archive';
        }

        return self::report(ProjectArtifact::FORMAT_ARCHIVE, [
            ['zip_container', 'Valid ZIP central directory with '.count($names).' entries; classified as '.$kind],
        ]);
    }

    /**
     * @param  list<array{string, string}>  $pairs
     * @return array{format: string, evidence: list<array{claim: string, detail: string}>, warnings: list<string>}
     */
    private static function report(string $format, array $pairs, array $warnings = []): array
    {
        return [
            'format' => $format,
            'evidence' => array_map(fn ($p) => ['claim' => $p[0], 'detail' => $p[1]], $pairs),
            'warnings' => $warnings,
        ];
    }
}
