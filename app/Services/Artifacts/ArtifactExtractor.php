<?php

namespace App\Services\Artifacts;

use App\Models\ProjectArtifact;
use App\Services\Binary\ApkParser;
use App\Services\Binary\ClassParser;
use App\Services\Binary\DexParser;
use App\Services\Binary\FormatDetector;
use App\Services\Binary\HexRecords;
use App\Services\Binary\MachOParser;
use App\Services\Binary\PeParser;
use App\Services\Binary\PrgParser;
use App\Services\Binary\ZipReader;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * Runs deterministic extraction for one stored artifact and records
 * an immutable JSON snapshot beside the raw bytes.
 *
 * Pure computation over bytes already on the private disk: no shell,
 * no network, no credentials, no access to other projects' files.
 * OS-level isolation (worker user, network namespace, cgroups) is an
 * owner-operated deployment step documented in the delivery report;
 * this class enforces byte budgets, output caps, and failure
 * categories in code.
 */
final class ArtifactExtractor
{
    /** Warnings that downgrade ready → partial (incomplete input/output). */
    private const PARTIAL_MARKERS = [
        'truncated', 'capped', 'out_of_bounds', 'unsupported_linktype',
        'unsupported_machine', 'unsupported_arch', 'bad_', 'snapshot_trimmed',
        'unreadable', 'unparsed', 'not_decoded', 'overlapping', 'unmapped',
        'broken', 'encrypted_',
    ];

    public static function detect(string $bytes, ?string $filename = null): string
    {
        return FormatDetector::format($bytes, $filename);
    }

    /** Full detection report with evidence (see FormatDetector). */
    public static function detectWithEvidence(string $bytes, ?string $filename = null): array
    {
        return FormatDetector::detect($bytes, $filename);
    }

    public static function extract(ProjectArtifact $artifact): void
    {
        $artifact->forceFill(['state' => ProjectArtifact::STATE_EXTRACTING])->save();

        try {
            $disk = Storage::disk('artifacts');
            $status = ArtifactStorage::status('artifacts', $artifact->rawPath());
            if ($status !== ArtifactStorage::OK) {
                ArtifactStorage::logUnavailable('artifacts', $artifact->rawPath(), $status, [
                    'artifact_id' => $artifact->id,
                    'project_id' => $artifact->project_id,
                ]);
                self::fail($artifact, $status === ArtifactStorage::MISSING ? 'missing_input' : 'storage_unreadable', ArtifactStorage::unavailableMessage());

                return;
            }

            if ($disk->size($artifact->rawPath()) !== (int) $artifact->byte_size
                || self::streamSha256($disk, $artifact->rawPath()) !== $artifact->sha256) {
                self::fail($artifact, 'integrity_mismatch', 'The stored file failed its integrity check. Re-upload it to investigate.');

                return;
            }

            $bytes = $disk->get($artifact->rawPath());
            if (strlen($bytes) !== (int) $artifact->byte_size) {
                self::fail($artifact, 'integrity_mismatch', 'The stored file failed its integrity check. Re-upload it to investigate.');

                return;
            }

            $detection = self::detectWithEvidence($bytes, $artifact->original_name);
            $format = $detection['format'];
            // Content wins over declaration: a declared-raw file that
            // turns out to be structured is parsed as what it is.
            // Truly unrecognized bytes stay raw (explicit opt-in only).
            if ($format === ProjectArtifact::FORMAT_UNKNOWN && $artifact->format === ProjectArtifact::FORMAT_RAW) {
                $format = ProjectArtifact::FORMAT_RAW;
            }
            if ($format === ProjectArtifact::FORMAT_UNKNOWN) {
                $artifact->forceFill(['format' => $format]);
                self::fail($artifact, 'unsupported_format', 'This file was not recognized as a supported executable, firmware, or capture format.');

                return;
            }

            // Filesystem images: record the detected flavor, then stop.
            // Content extraction runs isolated via the extract backend
            // (Queue analysis with the extraction target) — the
            // extractor itself never unpacks untrusted filesystems.
            if ($format === ProjectArtifact::FORMAT_FILESYSTEM) {
                $flavor = FormatDetector::filesystemFlavor(substr($bytes, 0, 4));
                $artifact->forceFill([
                    'format' => $format,
                    'state' => ProjectArtifact::STATE_NEEDS_CONFIGURATION,
                    'failure_code' => null,
                    'failure_message' => null,
                    'extractor' => 'filesystem-php/1.0.0',
                    'extraction_schema' => ProjectArtifact::EXTRACTION_SCHEMA,
                    'warnings' => [],
                    'limits_applied' => [],
                    'tech_attributes' => [
                        'detection' => $detection['evidence'],
                        'byte_size' => strlen($bytes),
                        'filesystem_flavor' => $flavor,
                    ],
                    'extracted_at' => now(),
                ])->save();

                return;
            }

            // Raw images carry no structural metadata: extraction
            // stops here and the maintainer configures the target.
            if ($format === ProjectArtifact::FORMAT_RAW) {
                $artifact->forceFill([
                    'format' => $format,
                    'state' => ProjectArtifact::STATE_NEEDS_CONFIGURATION,
                    'failure_code' => null,
                    'failure_message' => null,
                    'extractor' => 'raw-php/1.0.0',
                    'extraction_schema' => ProjectArtifact::EXTRACTION_SCHEMA,
                    'warnings' => [],
                    'limits_applied' => [],
                    'tech_attributes' => [
                        'detection' => $detection['evidence'],
                        'byte_size' => strlen($bytes),
                    ],
                    'extracted_at' => now(),
                ])->save();

                return;
            }

            $started = microtime(true);
            $parsed = self::parseFormat($format, $bytes);
            $elapsedMs = (int) ((microtime(true) - $started) * 1000);

            if (! $parsed['ok']) {
                $artifact->forceFill(['format' => $format, 'arch' => $parsed['arch'] ?? null]);
                self::fail($artifact, 'extract_'.($parsed['error'] ?? 'failed'), self::friendlyError($format, $parsed['error'] ?? 'failed'));

                return;
            }

            $extractor = self::extractorName($format);

            $snapshot = [
                'schema' => ProjectArtifact::EXTRACTION_SCHEMA,
                'extractor' => $extractor,
                'artifact' => ['id' => $artifact->id, 'sha256' => $artifact->sha256, 'format' => $format],
                'extracted_at' => now()->toIso8601String(),
                'elapsed_ms' => $elapsedMs,
                'limits' => self::limitsReport($format),
                'detection' => $detection['evidence'],
                'detection_warnings' => $detection['warnings'],
                'warnings' => array_values(array_unique(array_merge($detection['warnings'], $parsed['data']['warnings'] ?? []))),
                'data' => $parsed['data'],
            ];

            $encoded = self::fitSnapshot($snapshot, $format);
            if ($encoded === null) {
                $artifact->forceFill(['format' => $format, 'arch' => $parsed['arch'] ?? null]);
                self::fail($artifact, 'snapshot_too_large', 'Extraction output exceeded the storage cap. Narrow the capture and re-upload.');

                return;
            }

            $disk->put($artifact->snapshotPath(), $encoded);

            $artifact->forceFill([
                'format' => $format,
                'arch' => $parsed['arch'] ?? null,
                'state' => self::isPartial($snapshot) ? ProjectArtifact::STATE_PARTIAL : ProjectArtifact::STATE_READY,
                'failure_code' => null,
                'failure_message' => null,
                'extractor' => $extractor,
                'extraction_schema' => ProjectArtifact::EXTRACTION_SCHEMA,
                'warnings' => $snapshot['warnings'],
                'limits_applied' => $snapshot['limits'],
                'tech_attributes' => self::techAttributes($format, $parsed['data']),
                'extracted_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            report($e);
            self::fail($artifact, 'extractor_error', 'Extraction failed unexpectedly. The file was kept; try again or re-upload.');
        }
    }

    /** @return array{ok: bool, arch?: ?string, error?: string, data?: array<string, mixed>} */
    private static function parseFormat(string $format, string $bytes): array
    {
        return match ($format) {
            ProjectArtifact::FORMAT_ELF => ElfParser::parse($bytes, self::elfLimits()),
            ProjectArtifact::FORMAT_PCAP, ProjectArtifact::FORMAT_PCAPNG => PcapParser::parse($bytes, self::pcapLimits()),
            ProjectArtifact::FORMAT_PE => PeParser::parse($bytes, self::peLimits()),
            ProjectArtifact::FORMAT_MACHO => MachOParser::parse($bytes),
            ProjectArtifact::FORMAT_DEX => DexParser::parse($bytes),
            ProjectArtifact::FORMAT_APK => ApkParser::parse($bytes),
            ProjectArtifact::FORMAT_CLASS => ClassParser::parse($bytes),
            ProjectArtifact::FORMAT_IHEX => HexRecords::parseIntelHex($bytes),
            ProjectArtifact::FORMAT_SREC => HexRecords::parseSrec($bytes),
            ProjectArtifact::FORMAT_PRG => PrgParser::parse($bytes),
            ProjectArtifact::FORMAT_ARCHIVE => self::parseArchive($bytes),
            default => ['ok' => false, 'error' => 'no_parser'],
        };
    }

    private static function extractorName(string $format): string
    {
        return match ($format) {
            ProjectArtifact::FORMAT_ELF => ElfParser::NAME.'/'.ElfParser::VERSION,
            ProjectArtifact::FORMAT_PCAP, ProjectArtifact::FORMAT_PCAPNG => PcapParser::NAME.'/'.PcapParser::VERSION,
            ProjectArtifact::FORMAT_PE => PeParser::NAME.'/'.PeParser::VERSION,
            ProjectArtifact::FORMAT_MACHO => MachOParser::NAME.'/'.MachOParser::VERSION,
            ProjectArtifact::FORMAT_DEX => DexParser::NAME.'/'.DexParser::VERSION,
            ProjectArtifact::FORMAT_APK => ApkParser::NAME.'/'.ApkParser::VERSION,
            ProjectArtifact::FORMAT_CLASS => ClassParser::NAME.'/'.ClassParser::VERSION,
            ProjectArtifact::FORMAT_IHEX, ProjectArtifact::FORMAT_SREC => 'hexrec-php/1.0.0',
            ProjectArtifact::FORMAT_PRG => PrgParser::NAME.'/'.PrgParser::VERSION,
            ProjectArtifact::FORMAT_ARCHIVE => 'zipinspect-php/1.0.0',
            default => 'unknown/0',
        };
    }

    /** @return array{ok: bool, error?: string, data?: array<string, mixed>} */
    private static function parseArchive(string $bytes): array
    {
        $cd = ZipReader::centralDirectory($bytes);
        if (! ($cd['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'zip_'.($cd['error'] ?? 'unreadable')];
        }

        $entries = $cd['entries'] ?? [];
        $warnings = [];
        if ($cd['truncated'] ?? false) {
            $warnings[] = 'entries_capped';
        }
        foreach ($entries as $entry) {
            if ($entry['encrypted']) {
                $warnings[] = 'encrypted_member:'.$entry['name'];
            }
            if ($entry['method'] !== 0 && $entry['method'] !== 8) {
                $warnings[] = 'unsupported_method:'.$entry['name'];
            }
        }

        $classCount = count(array_filter($entries, fn ($e) => str_ends_with($e['name'], '.class')));

        return [
            'ok' => true,
            'arch' => $classCount > 0 ? 'jvm' : null,
            'data' => [
                'kind' => $classCount > 0 ? 'jar' : 'zip',
                'member_count' => count($entries),
                'class_count' => $classCount,
                'members' => array_map(fn ($e) => [
                    'name' => mb_substr($e['name'], 0, 200),
                    'size' => $e['size'],
                    'method' => $e['method'] === 0 ? 'stored' : ($e['method'] === 8 ? 'deflated' : 'method:'.$e['method']),
                    'encrypted' => $e['encrypted'],
                ], array_slice($entries, 0, 500)),
                'warnings' => array_slice(array_unique($warnings), 0, 100),
            ],
        ];
    }

    /** @return array<string, int> */
    public static function elfLimits(): array
    {
        return [
            'max_sections' => (int) config('artifacts.elf_max_sections'),
            'max_symbols' => (int) config('artifacts.elf_max_symbols'),
            'max_strings' => (int) config('artifacts.elf_max_strings'),
            'max_string_bytes' => (int) config('artifacts.elf_max_string_bytes'),
            'max_endpoints' => 200,
        ];
    }

    /** @return array<string, int> */
    private static function pcapLimits(): array
    {
        return [
            'max_packets' => (int) config('artifacts.pcap_max_packets'),
            'max_flows' => (int) config('artifacts.pcap_max_flows'),
            'max_dns' => (int) config('artifacts.pcap_max_dns'),
            'max_payload_bytes' => (int) config('artifacts.pcap_max_payload_bytes'),
        ];
    }

    /** @return array<string, int> */
    public static function peLimits(): array
    {
        return [
            'max_sections' => 96,
            'max_imports' => 500,
            'max_exports' => 500,
            'max_strings' => (int) config('artifacts.elf_max_strings'),
        ];
    }

    /** @return array<string, mixed> */
    private static function limitsReport(string $format): array
    {
        return match ($format) {
            ProjectArtifact::FORMAT_ELF => self::elfLimits(),
            ProjectArtifact::FORMAT_PE => self::peLimits(),
            ProjectArtifact::FORMAT_PCAP, ProjectArtifact::FORMAT_PCAPNG => self::pcapLimits(),
            default => [],
        };
    }

    /** @param array<string, mixed> $snapshot */
    private static function fitSnapshot(array &$snapshot, string $format): ?string
    {
        $cap = (int) config('artifacts.max_snapshot_bytes');
        $encoded = json_encode($snapshot);
        if ($encoded !== false && strlen($encoded) <= $cap) {
            return $encoded;
        }

        // Deterministically shed per-packet detail (PCAP) until it fits.
        if ($format !== ProjectArtifact::FORMAT_ELF && isset($snapshot['data']['packets'])) {
            $packets = $snapshot['data']['packets'];
            while (count($packets) > 100) {
                $packets = array_slice($packets, 0, (int) (count($packets) / 2));
                $snapshot['data']['packets'] = $packets;
                $encoded = json_encode($snapshot);
                if ($encoded !== false && strlen($encoded) <= $cap) {
                    $snapshot['warnings'][] = 'snapshot_trimmed';
                    $snapshot['data']['warnings'][] = 'snapshot_trimmed';

                    return json_encode($snapshot) ?: null;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $snapshot */
    private static function isPartial(array $snapshot): bool
    {
        foreach ($snapshot['warnings'] as $warning) {
            foreach (self::PARTIAL_MARKERS as $marker) {
                if (str_contains((string) $warning, $marker)) {
                    return true;
                }
            }
        }

        return (bool) ($snapshot['data']['string_stats']['truncated'] ?? false)
            || (bool) ($snapshot['data']['symbol_stats']['truncated'] ?? false)
            || (bool) ($snapshot['data']['flows_truncated'] ?? false);
    }

    /** @return array<string, mixed> */
    private static function techAttributes(string $format, array $data): array
    {
        if ($format === ProjectArtifact::FORMAT_ELF) {
            return [
                'class' => $data['class'],
                'endian' => $data['endian'],
                'machine' => $data['machine']['name'],
                'object_type' => $data['object_type'],
                'entry_point' => $data['entry_point'],
                'sections' => count($data['sections']),
                'segments' => count($data['segments']),
                'symbols' => count($data['symbols']),
                'needed' => $data['dynamic']['needed'] ?? [],
                'strings' => $data['string_stats']['kept'],
                'endpoints' => count($data['endpoints']),
            ];
        }

        if ($format === ProjectArtifact::FORMAT_PE) {
            return [
                'pe_type' => $data['pe_type'],
                'machine' => $data['machine']['name'],
                'runtime' => $data['runtime'],
                'is_dll' => $data['is_dll'],
                'entry_rva' => $data['entry_rva'],
                'sections' => count($data['sections']),
                'imports' => $data['import_count'],
                'import_modules' => count($data['imports']),
                'exports' => $data['export_count'],
                'strings' => $data['string_stats']['kept'],
            ];
        }

        if ($format === ProjectArtifact::FORMAT_MACHO) {
            return [
                'kind' => $data['kind'],
                'cpu' => $data['cpu_name'] ?? ($data['members'][0]['cpu_name'] ?? null),
                'members' => $data['member_count'] ?? 1,
                'segments' => count($data['segments'] ?? []),
                'dylibs' => count($data['dylibs'] ?? []),
            ];
        }

        if ($format === ProjectArtifact::FORMAT_DEX) {
            return [
                'version' => $data['version'],
                'classes' => $data['class_count'],
                'methods' => $data['method_count'],
                'strings' => $data['string_count'],
            ];
        }

        if ($format === ProjectArtifact::FORMAT_APK) {
            return [
                'members' => $data['member_count'],
                'dex_files' => $data['dex_count'],
                'native_libs' => $data['native_lib_count'],
                'manifest' => $data['manifest']['kind'],
                'split_markers' => $data['split_markers'],
            ];
        }

        if ($format === ProjectArtifact::FORMAT_CLASS) {
            return [
                'java' => $data['version']['java'],
                'class' => $data['class_name'],
                'super' => $data['super_name'],
                'pool_entries' => $data['pool_entries'],
                'member_refs' => $data['member_refs'],
            ];
        }

        if ($format === ProjectArtifact::FORMAT_IHEX || $format === ProjectArtifact::FORMAT_SREC) {
            return [
                'records' => $data['records'],
                'data_bytes' => $data['data_bytes'],
                'segments' => count($data['segments']),
                'min_address' => $data['min_address'],
                'max_address' => $data['max_address'],
                'entry' => $data['entry'],
                'bad_checksums' => $data['bad_checksums'],
            ];
        }

        if ($format === ProjectArtifact::FORMAT_PRG) {
            return [
                'load_address' => $data['load_address'],
                'basic_lines' => count($data['basic_lines']),
                'sys_targets' => $data['sys_targets'],
                'payload_bytes' => $data['payload_bytes'],
            ];
        }

        if ($format === ProjectArtifact::FORMAT_ARCHIVE) {
            return [
                'kind' => $data['kind'],
                'members' => $data['member_count'],
                'classes' => $data['class_count'],
            ];
        }

        return [
            'packets' => $data['packet_count'],
            'time_range' => $data['time_range'],
            'interfaces' => count($data['interfaces']),
            'endpoints' => count($data['endpoints']),
            'protocols' => $data['protocols'],
            'flows' => count($data['flows']),
            'dns_queries' => count($data['dns']['queries']),
            'resolutions' => count($data['dns']['resolutions']),
            'tcp' => $data['tcp'],
            'http' => count($data['http']),
            'tls' => count($data['tls']),
        ];
    }

    private static function friendlyError(string $format, string $error): string
    {
        if (str_starts_with($error, 'malformed_record:')) {
            return 'A hex record is malformed; the file looks truncated or corrupted.';
        }

        return match ($error) {
            'not_elf' => 'This file is not an ELF binary.',
            'not_capture' => 'This file is not a PCAP or PCAPNG capture.',
            'not_pe' => 'This file is not a Windows executable.',
            'not_macho' => 'This file is not a Mach-O binary.',
            'not_dex' => 'This file is not a DEX file.',
            'not_class' => 'This file is not a Java class file.',
            'too_small' => 'The file is too small to hold this format.',
            'unsupported_class', 'unsupported_endian' => 'This ELF variant is not supported.',
            'truncated_header' => 'The file ends inside its header; it looks truncated.',
            'malformed_header' => 'The file header could not be decoded.',
            'bad_global_header', 'bad_byte_order' => 'The capture header is malformed.',
            'bad_pe_signature' => 'The PE signature is missing; the file looks truncated.',
            'bad_optional_magic' => 'The PE optional header is not PE32 or PE32+.',
            'truncated_coff', 'truncated_optional', 'truncated_sections' => 'The PE headers end abruptly; the file looks truncated.',
            'too_many_sections' => 'The section count is implausible; the file looks corrupted.',
            'bad_endian_tag' => 'The DEX endian tag is invalid.',
            'table_too_large' => 'A DEX table size is implausible; the file looks corrupted.',
            'truncated_class_defs', 'truncated_method_ids' => 'The DEX tables end abruptly; the file looks truncated.',
            'no_manifest' => 'No AndroidManifest.xml was found; this is not an installable APK.',
            'no_dex' => 'No classes.dex was found; this APK has no Dalvik bytecode.',
            'bad_version' => 'The class-file version is outside the supported range.',
            'truncated_pool', 'truncated_body' => 'The class file ends abruptly; it looks truncated.',
            'fat64_unsupported' => '64-bit FAT headers are not supported yet.',
            'bad_fat_count', 'truncated_fat' => 'The FAT header is malformed.',
            'too_many_commands', 'truncated_commands', 'truncated_segment' => 'The Mach-O load commands end abruptly; the file looks truncated.',
            'no_parser' => 'No parser is available for this format.',
            default => 'This file could not be inspected ('.$format.').',
        };
    }

    /**
     * Constant-memory SHA-256 over the stored file. Returns null when
     * the file cannot be streamed (treated as an integrity failure).
     */
    /** Streaming SHA-256 over a stored file; null when it cannot be read. */
    public static function streamSha256(FilesystemAdapter $disk, string $path): ?string
    {
        try {
            $stream = $disk->readStream($path);
        } catch (\Throwable) {
            return null;
        }
        if ($stream === false) {
            return null;
        }
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return hash_final($context);
    }

    private static function fail(ProjectArtifact $artifact, string $code, string $message): void
    {
        $artifact->forceFill([
            'state' => ProjectArtifact::STATE_FAILED,
            'failure_code' => $code,
            'failure_message' => $message,
            'extracted_at' => now(),
        ])->save();
    }
}
