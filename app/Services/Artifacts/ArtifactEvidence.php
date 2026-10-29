<?php

namespace App\Services\Artifacts;

use App\Models\BinaryAnalysis;
use App\Models\ProjectArtifact;

/**
 * Redacted, bounded model-facing view over an extraction snapshot.
 *
 * Raw bytes never leave the artifacts disk: tools and prompts see
 * only this layer. Every string passes through the redactor, output
 * is capped, and partial/unsupported states travel with the data so
 * the model reports limits honestly instead of guessing.
 */
final class ArtifactEvidence
{
    /**
     * Coverage summary for one artifact (tool: list_artifacts).
     *
     * @return array<string, mixed>
     */
    public static function coverage(ProjectArtifact $artifact): array
    {
        $snapshot = $artifact->isUsable() ? $artifact->readSnapshot() : null;
        $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];

        $coverage = [
            'artifact_id' => $artifact->id,
            'name' => $artifact->original_name,
            'format' => $artifact->format,
            'arch' => $artifact->arch,
            'state' => $artifact->state,
            'sha256_prefix' => substr($artifact->sha256, 0, 16),
            'warnings' => array_slice($artifact->warnings ?? [], 0, 8),
        ];

        if ($artifact->state === ProjectArtifact::STATE_FAILED) {
            $coverage['failure'] = $artifact->failure_message;

            return $coverage;
        }

        if ($snapshot === null) {
            $coverage['note'] = 'Extraction is still running. Retry shortly.';

            return $coverage;
        }

        if ($artifact->format === ProjectArtifact::FORMAT_ELF) {
            $coverage['elf'] = [
                'sections' => count($data['sections'] ?? []),
                'symbols' => count($data['symbols'] ?? []),
                'needed' => array_slice($data['dynamic']['needed'] ?? [], 0, 10),
                'strings' => count($data['strings'] ?? []),
                'endpoint_candidates' => count($data['endpoints'] ?? []),
                'entry_point' => $data['entry_point'] ?? null,
            ];
        } else {
            $coverage['capture'] = [
                'packets' => $data['packet_count'] ?? 0,
                'time_range' => $data['time_range'] ?? null,
                'flows' => count($data['flows'] ?? []),
                'dns_questions' => count($data['dns']['queries'] ?? []),
                'endpoints' => array_slice($data['endpoints'] ?? [], 0, 10),
                'interfaces' => $data['interfaces'] ?? [],
            ];
        }

        return $coverage;
    }

    /**
     * Bounded redacted context block for the turn system prompt.
     */
    public static function contextBlock(ProjectArtifact $artifact): string
    {
        if (! $artifact->isUsable()) {
            return "[A{$artifact->id}] {$artifact->original_name}: not ready ({$artifact->state}).";
        }

        $max = (int) config('artifacts.context_max_bytes', 12000);
        $snapshot = $artifact->readSnapshot();
        $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];

        $lines = [];
        $lines[] = "[A{$artifact->id}] {$artifact->original_name} ({$artifact->format}".($artifact->arch ? ", {$artifact->arch}" : '').', '.substr($artifact->sha256, 0, 12).'…):';

        foreach (array_slice($artifact->warnings ?? [], 0, 5) as $warning) {
            $lines[] = '- warning: '.$warning;
        }

        if ($artifact->format === ProjectArtifact::FORMAT_ELF) {
            $machine = is_array($data['machine'] ?? null) ? ($data['machine']['name'] ?? '?') : '?';
            $lines[] = '- header: '.($data['class'] ?? '?').' '.($data['endian'] ?? '?').' '.$machine.' '.($data['object_type'] ?? '?').' entry='.($data['entry_point'] ?? '?');
            foreach (array_slice($data['dynamic']['needed'] ?? [], 0, 8) as $lib) {
                $lines[] = '- needs: '.$lib;
            }
            foreach (array_slice($data['endpoints'] ?? [], 0, 15) as $endpoint) {
                $value = is_array($endpoint) ? ($endpoint['value'] ?? '') : (string) $endpoint;
                $kind = is_array($endpoint) ? ($endpoint['kind'] ?? 'endpoint') : 'endpoint';
                $lines[] = '- candidate '.$kind.': '.$value;
            }
        } elseif ($artifact->format === ProjectArtifact::FORMAT_PE) {
            $machine = is_array($data['machine'] ?? null) ? ($data['machine']['name'] ?? '?') : '?';
            $lines[] = '- header: '.($data['pe_type'] ?? '?').' '.$machine.' runtime='.($data['runtime'] ?? '?').(($data['is_dll'] ?? false) ? ' DLL' : '').' entry_rva='.($data['entry_rva'] ?? '?');
            foreach (array_slice($data['imports'] ?? [], 0, 8) as $module) {
                if (is_array($module)) {
                    $lines[] = '- imports: '.($module['dll'] ?? '?').' ('.count($module['functions'] ?? []).' shown)';
                }
            }
            $lines[] = '- exports: '.($data['export_count'] ?? 0);
        } elseif ($artifact->format === ProjectArtifact::FORMAT_MACHO) {
            $lines[] = '- header: Mach-O '.($data['kind'] ?? '?').' cpu='.($data['cpu_name'] ?? $data['members'][0]['cpu_name'] ?? '?').' members='.($data['member_count'] ?? 1);
            foreach (array_slice($data['dylibs'] ?? [], 0, 8) as $dylib) {
                $lines[] = '- dylib: '.$dylib;
            }
        } elseif ($artifact->format === ProjectArtifact::FORMAT_DEX) {
            $lines[] = '- dex: v'.($data['version'] ?? '?').' classes='.($data['class_count'] ?? 0).' methods='.($data['method_count'] ?? 0).' strings='.($data['string_count'] ?? 0);
            foreach (array_slice($data['classes'] ?? [], 0, 10) as $class) {
                $lines[] = '- class: '.(is_array($class) ? ($class['type'] ?? '?') : '?');
            }
        } elseif ($artifact->format === ProjectArtifact::FORMAT_APK) {
            $lines[] = '- apk: members='.($data['member_count'] ?? 0).' dex='.($data['dex_count'] ?? 0).' native_libs='.($data['native_lib_count'] ?? 0).' manifest='.($data['manifest']['kind'] ?? '?');
            foreach (array_slice($data['dexes'] ?? [], 0, 4) as $dex) {
                $lines[] = '- dex: '.($dex['name'] ?? '?').' classes='.($dex['summary']['classes'] ?? 0).' methods='.($dex['summary']['methods'] ?? 0);
            }
            foreach (array_slice($data['native_libs'] ?? [], 0, 4) as $lib) {
                $lines[] = '- native: '.($lib['name'] ?? '?').' arch='.($lib['arch'] ?? '?');
            }
        } elseif ($artifact->format === ProjectArtifact::FORMAT_CLASS) {
            $lines[] = '- class: '.($data['class_name'] ?? '?').' extends '.($data['super_name'] ?? '?').' ('.($data['version']['java'] ?? '?').')';
        } elseif ($artifact->format === ProjectArtifact::FORMAT_IHEX || $artifact->format === ProjectArtifact::FORMAT_SREC) {
            $lines[] = '- hex: records='.($data['records'] ?? 0).' data_bytes='.($data['data_bytes'] ?? 0).' segments='.count($data['segments'] ?? []);
            foreach (array_slice($data['segments'] ?? [], 0, 8) as $segment) {
                $lines[] = '- segment: '.sprintf('0x%x', $segment['start'] ?? 0).'…'.sprintf('0x%x', $segment['end'] ?? 0);
            }
        } elseif ($artifact->format === ProjectArtifact::FORMAT_PRG) {
            $lines[] = '- prg: load='.sprintf('0x%x', $data['load_address'] ?? 0).' basic_lines='.count($data['basic_lines'] ?? []).' payload_bytes='.($data['payload_bytes'] ?? 0);
            foreach (array_slice($data['basic_lines'] ?? [], 0, 10) as $line) {
                $lines[] = '- basic '.($line['number'] ?? '?').' '.substr((string) ($line['text'] ?? ''), 0, 120);
            }
        } elseif ($artifact->format === ProjectArtifact::FORMAT_ARCHIVE) {
            $lines[] = '- archive: kind='.($data['kind'] ?? '?').' members='.($data['member_count'] ?? 0).' classes='.($data['class_count'] ?? 0);
        } elseif ($artifact->format === ProjectArtifact::FORMAT_RAW || $artifact->format === ProjectArtifact::FORMAT_UNKNOWN) {
            $lines[] = '- raw image: '.$artifact->byte_size.' bytes, no structural format — needs explicit target configuration.';
        } else {
            $range = is_array($data['time_range'] ?? null) ? ($data['time_range']['start'] ?? '?').' … '.($data['time_range']['end'] ?? '?') : 'unknown time';
            $lines[] = '- packets: '.($data['packet_count'] ?? 0).' ('.$range.')';
            foreach (array_slice($data['dns']['queries'] ?? [], 0, 10) as $dns) {
                if (! is_array($dns)) {
                    continue;
                }
                $lines[] = '- dns: '.($dns['name'] ?? '?').' ('.($dns['type'] ?? '?').') packet '.($dns['packet'] ?? '?');
            }
            foreach (array_slice(array_values($data['dns']['resolutions'] ?? []), 0, 5) as $resolution) {
                if (! is_array($resolution)) {
                    continue;
                }
                $addresses = isset($resolution['addresses']) && is_array($resolution['addresses']) ? implode(',', array_slice($resolution['addresses'], 0, 3)) : '';
                $lines[] = '- resolved: '.($resolution['name'] ?? '?').' → '.$addresses;
            }
            foreach (array_slice($data['flows'] ?? [], 0, 10) as $flow) {
                if (! is_array($flow)) {
                    continue;
                }
                $lines[] = '- flow: '.($flow['a'] ?? '?').' + '.($flow['b'] ?? '?').' (unordered) '.($flow['proto'] ?? '?').' packets='.($flow['packets'] ?? 0).' outcome='.($flow['outcome'] ?? '?');
            }
            foreach (array_slice($data['http'] ?? [], 0, 8) as $http) {
                if (! is_array($http)) {
                    continue;
                }
                $headers = is_array($http['headers'] ?? null) ? $http['headers'] : [];
                if (isset($http['method'])) {
                    $lines[] = '- http: '.($http['method'] ?? '?').' '.($http['target'] ?? '?').' host='.($headers['host'] ?? '?').' (packet '.($http['packet'] ?? '?').')';
                } else {
                    $lines[] = '- http: status '.($http['status'] ?? '?').' (packet '.($http['packet'] ?? '?').')';
                }
            }
            foreach (array_slice($data['tls'] ?? [], 0, 5) as $tls) {
                if (! is_array($tls)) {
                    continue;
                }
                $lines[] = '- tls: sni='.($tls['sni'] ?? '(absent)').' (packet '.($tls['packet'] ?? '?').')';
            }
        }

        foreach (BinaryAnalysis::where('project_artifact_id', $artifact->id)->latest('id')->limit(3)->get() as $run) {
            $lines[] = '- analysis #'.$run->id.': '.$run->adapter.'/'.$run->adapter_version.' target='.($run->target ?? '?').' status='.$run->status;
        }

        $block = Redactor::redactText(implode("\n", $lines));

        return strlen($block) > $max ? substr($block, 0, $max)."\n…(truncated to {$max} bytes)" : $block;
    }

    /**
     * Present one stored flow without implying direction: the stored
     * a/b pair is lexicographic, so the wire form names unordered
     * endpoints and only states initiator/responder when the parser
     * observed a lone SYN from that side.
     *
     * @param  array<string, mixed>  $flow
     * @return array<string, mixed>
     */
    public static function presentFlow(array $flow): array
    {
        $initiator = $flow['initiator'] ?? null;

        return [
            'proto' => $flow['proto'] ?? null,
            'endpoint_1' => $flow['a'] ?? null,
            'endpoint_2' => $flow['b'] ?? null,
            'endpoints' => 'unordered',
            'initiator' => is_string($initiator) ? $initiator : null,
            'responder' => is_string($initiator) ? ($flow['responder'] ?? null) : null,
            'packets' => $flow['packets'] ?? 0,
            'bytes' => $flow['bytes'] ?? 0,
            'first_packet' => $flow['first_packet'] ?? null,
            'last_packet' => $flow['last_packet'] ?? null,
            'first_ts' => $flow['first_ts'] ?? null,
            'last_ts' => $flow['last_ts'] ?? null,
            'outcome' => $flow['outcome'] ?? null,
            'retransmit_indicators' => $flow['retrans'] ?? 0,
        ];
    }

    /**
     * Normalize one stored HTTP entry to the safe model/viewer shape:
     * method, host, redacted target, status — never raw headers.
     *
     * @param  array<string, mixed>  $http
     * @return array<string, mixed>
     */
    public static function presentHttp(array $http): array
    {
        $headers = is_array($http['headers'] ?? null) ? $http['headers'] : [];
        $safeHeaders = [];
        foreach (['host', 'content-type', 'content-length', 'server', 'location'] as $name) {
            if (isset($headers[$name])) {
                $safeHeaders[$name] = substr((string) $headers[$name], 0, 256);
            }
        }

        return [
            'http_kind' => isset($http['method']) ? 'request' : 'response',
            'method' => $http['method'] ?? null,
            'host' => $headers['host'] ?? null,
            'target' => isset($http['target']) ? Redactor::redactUrl((string) $http['target']) : null,
            'status' => $http['status'] ?? null,
            'version' => $http['version'] ?? null,
            'headers' => $safeHeaders,
            'packet' => $http['packet'] ?? null,
            'ts' => $http['ts'] ?? null,
        ];
    }

    /**
     * Bounded, redacted detail structures for the artifact viewer.
     * Everything is safe to render escaped: secrets redacted, lists
     * capped with explicit truncation counts.
     *
     * @return array<string, mixed>
     */
    public static function viewerDetails(ProjectArtifact $artifact, ?array $snapshot): array
    {
        $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];

        if ($artifact->format === ProjectArtifact::FORMAT_ELF) {
            $sections = [];
            foreach (array_slice($data['sections'] ?? [], 0, 50) as $section) {
                if (! is_array($section)) {
                    continue;
                }
                $sections[] = [
                    'name' => substr((string) ($section['name'] ?? '?'), 0, 64),
                    'type' => $section['type'] ?? null,
                    'size' => $section['size'] ?? null,
                    'flags' => $section['flags'] ?? null,
                    'file_offset' => isset($section['file_offset']) ? sprintf('0x%x', (int) $section['file_offset']) : null,
                    'out_of_bounds' => $section['out_of_bounds'] ?? false,
                ];
            }

            $symbols = [];
            foreach (array_slice($data['symbols'] ?? [], 0, 50) as $symbol) {
                if (! is_array($symbol)) {
                    continue;
                }
                $symbols[] = [
                    'name' => substr((string) ($symbol['name'] ?? '(unnamed)'), 0, 128),
                    'value' => $symbol['value'] ?? null,
                    'address_kind' => 'virtual_address',
                    'binding' => $symbol['binding'] ?? null,
                    'section' => $symbol['section'] ?? null,
                ];
            }

            $strings = [];
            foreach (array_slice($data['strings'] ?? [], 0, 100) as $string) {
                if (! is_array($string)) {
                    continue;
                }
                $strings[] = [
                    'offset' => sprintf('0x%x', (int) ($string['offset'] ?? 0)),
                    'value' => Redactor::redactText(substr((string) ($string['value'] ?? ''), 0, 256)),
                ];
            }

            $endpoints = [];
            foreach ($data['endpoints'] ?? [] as $endpoint) {
                if (! is_array($endpoint)) {
                    continue;
                }
                $endpoints[] = [
                    'kind' => $endpoint['kind'] ?? 'endpoint',
                    'value' => Redactor::redactUrl(substr((string) ($endpoint['value'] ?? ''), 0, 256)),
                    'offset' => isset($endpoint['offset']) ? sprintf('0x%x', (int) $endpoint['offset']) : null,
                ];
            }

            return [
                'sections' => $sections,
                'sections_total' => count($data['sections'] ?? []),
                'symbols' => $symbols,
                'symbols_total' => count($data['symbols'] ?? []),
                'strings' => $strings,
                'strings_total' => count($data['strings'] ?? []),
                'endpoints' => $endpoints,
            ];
        }

        if (! in_array($artifact->format, [ProjectArtifact::FORMAT_PCAP, ProjectArtifact::FORMAT_PCAPNG], true)) {
            return self::binaryDetails($artifact, $data);
        }

        $packets = [];
        foreach (array_slice($data['packets'] ?? [], 0, 100) as $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $packets[] = [
                'n' => $meta['n'] ?? null,
                'ts' => $meta['ts'] ?? null,
                'iface' => $meta['iface'] ?? null,
                'src' => $meta['src'] ?? null,
                'dst' => $meta['dst'] ?? null,
                'proto' => $meta['proto'] ?? null,
                'sport' => $meta['sport'] ?? null,
                'dport' => $meta['dport'] ?? null,
                'len' => $meta['len'] ?? null,
                'http' => $meta['http'] ?? null,
                'tls' => $meta['tls'] ?? null,
                'note' => $meta['note'] ?? null,
            ];
        }

        $flows = [];
        foreach (array_slice($data['flows'] ?? [], 0, 50) as $flow) {
            if (is_array($flow)) {
                $flows[] = self::presentFlow($flow);
            }
        }

        $http = [];
        foreach ($data['http'] ?? [] as $entry) {
            if (is_array($entry)) {
                $http[] = self::presentHttp($entry);
            }
        }

        return [
            'packets' => $packets,
            'packets_total' => $data['packet_count'] ?? count($packets),
            'flows' => $flows,
            'flows_total' => count($data['flows'] ?? []),
            'dns_queries' => array_slice($data['dns']['queries'] ?? [], 0, 50),
            'dns_queries_total' => count($data['dns']['queries'] ?? []),
            'resolutions' => array_slice(array_values($data['dns']['resolutions'] ?? []), 0, 50),
            'resolutions_total' => count($data['dns']['resolutions'] ?? []),
            'http' => $http,
            'tls' => array_slice($data['tls'] ?? [], 0, 50),
            'discovery' => array_slice($data['discovery'] ?? [], 0, 50),
        ];
    }

    /**
     * Bounded, redacted detail rows for binary formats. Every list is
     * capped and every extracted string passes the redactor; viewers
     * must escape again on output.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function binaryDetails(ProjectArtifact $artifact, array $data): array
    {
        $strings = [];
        foreach (array_slice($data['strings'] ?? [], 0, 100) as $string) {
            if (! is_array($string)) {
                continue;
            }
            $strings[] = [
                'offset' => sprintf('0x%x', (int) ($string['offset'] ?? 0)),
                'value' => Redactor::redactText(substr((string) ($string['value'] ?? ''), 0, 256)),
            ];
        }

        $details = [
            'kind' => 'binary',
            'strings' => $strings,
            'strings_total' => $data['string_stats']['kept'] ?? count($data['strings'] ?? []),
            'detection' => [],
            'panels' => [],
        ];

        switch ($artifact->format) {
            case ProjectArtifact::FORMAT_PE:
                $sections = [];
                foreach (array_slice($data['sections'] ?? [], 0, 50) as $section) {
                    $sections[] = [
                        'name' => substr((string) ($section['name'] ?? '?'), 0, 64),
                        'virtual_address' => sprintf('0x%x', (int) ($section['virtual_address'] ?? 0)),
                        'raw_offset' => sprintf('0x%x', (int) ($section['raw_offset'] ?? 0)),
                        'size' => $section['raw_size'] ?? 0,
                        'flags' => ($section['executable'] ?? false ? 'X' : '-').(($section['writable'] ?? false) ? 'W' : '-'),
                    ];
                }
                $imports = [];
                foreach (array_slice($data['imports'] ?? [], 0, 30) as $module) {
                    $imports[] = [
                        'dll' => substr((string) ($module['dll'] ?? '?'), 0, 128),
                        'functions' => array_slice($module['functions'] ?? [], 0, 25),
                        'function_count' => count($module['functions'] ?? []),
                    ];
                }
                $details['panels'] = [
                    ['title' => 'Sections (virtual address + file offset)', 'rows' => $sections],
                    ['title' => 'Imports ('.($data['import_count'] ?? 0).' functions)', 'imports' => $imports],
                    ['title' => 'Exports ('.($data['export_count'] ?? 0).')', 'names' => array_slice($data['exports'] ?? [], 0, 100)],
                ];
                break;

            case ProjectArtifact::FORMAT_MACHO:
                $members = [];
                foreach (($data['members'] ?? []) as $member) {
                    $members[] = [
                        'cpu' => ($member['cpu_name'] ?? '?').' (arch '.($member['arch'] ?? '?').')',
                        'offset' => sprintf('0x%x', (int) ($member['offset'] ?? 0)),
                        'size' => $member['size'] ?? 0,
                    ];
                }
                $segments = [];
                $segmentsData = $data['segments'] ?? $data['first_member']['segments'] ?? [];
                foreach (array_slice($segmentsData, 0, 50) as $segment) {
                    $segments[] = [
                        'name' => substr((string) ($segment['name'] ?? '?'), 0, 64),
                        'vmaddr' => sprintf('0x%x', (int) ($segment['vmaddr'] ?? 0)),
                        'sections' => count($segment['sections'] ?? []),
                    ];
                }
                $details['panels'] = [
                    ['title' => 'FAT members', 'rows' => $members],
                    ['title' => 'Segments', 'rows' => $segments],
                    ['title' => 'Linked libraries', 'names' => array_slice($data['dylibs'] ?? $data['first_member']['dylibs'] ?? [], 0, 100)],
                ];
                break;

            case ProjectArtifact::FORMAT_DEX:
                $details['panels'] = [
                    ['title' => 'Classes ('.($data['class_count'] ?? 0).')', 'names' => array_map(fn ($c) => $c['type'] ?? '?', array_slice($data['classes'] ?? [], 0, 200))],
                    ['title' => 'Methods ('.($data['method_count'] ?? 0).')', 'names' => array_slice($data['methods'] ?? [], 0, 200)],
                ];
                break;

            case ProjectArtifact::FORMAT_APK:
                $dexRows = [];
                foreach (($data['dexes'] ?? []) as $dex) {
                    $dexRows[] = [
                        'name' => $dex['name'] ?? '?',
                        'classes' => $dex['summary']['classes'] ?? 0,
                        'methods' => $dex['summary']['methods'] ?? 0,
                    ];
                }
                $soRows = [];
                foreach (($data['native_libs'] ?? []) as $lib) {
                    $soRows[] = [
                        'name' => $lib['name'] ?? '?',
                        'abi' => $lib['abi'] ?? '?',
                        'arch' => $lib['arch'] ?? '?',
                    ];
                }
                $details['panels'] = [
                    ['title' => 'Manifest', 'rows' => [['kind' => $data['manifest']['kind'] ?? '?', 'strings' => $data['manifest']['string_count'] ?? 0]]],
                    ['title' => 'DEX files', 'rows' => $dexRows],
                    ['title' => 'Native libraries', 'rows' => $soRows],
                    ['title' => 'Members ('.($data['member_count'] ?? 0).')', 'names' => array_map(fn ($m) => $m['name'] ?? '?', array_slice($data['members'] ?? [], 0, 200))],
                ];
                break;

            case ProjectArtifact::FORMAT_CLASS:
                $details['panels'] = [
                    ['title' => 'Pool strings', 'names' => array_slice($data['pool_strings'] ?? [], 0, 200)],
                ];
                break;

            case ProjectArtifact::FORMAT_IHEX:
            case ProjectArtifact::FORMAT_SREC:
                $rows = [];
                foreach (array_slice($data['segments'] ?? [], 0, 64) as $segment) {
                    $rows[] = [
                        'range' => sprintf('0x%x', $segment['start'] ?? 0).'…'.sprintf('0x%x', $segment['end'] ?? 0),
                        'length' => $segment['length'] ?? 0,
                    ];
                }
                $details['panels'] = [['title' => 'Address segments', 'rows' => $rows]];
                break;

            case ProjectArtifact::FORMAT_PRG:
                $lines = [];
                foreach (array_slice($data['basic_lines'] ?? [], 0, 100) as $line) {
                    $lines[] = ($line['number'] ?? '?').' '.substr((string) ($line['text'] ?? ''), 0, 160);
                }
                $details['panels'] = [
                    ['title' => 'BASIC listing', 'names' => $lines],
                    ['title' => 'SYS targets (machine-code entry candidates)', 'names' => array_map(fn ($t) => sprintf('%d (0x%x)', $t, $t), $data['sys_targets'] ?? [])],
                ];
                break;

            case ProjectArtifact::FORMAT_ARCHIVE:
                $details['panels'] = [
                    ['title' => 'Members ('.($data['member_count'] ?? 0).')', 'names' => array_map(fn ($m) => ($m['name'] ?? '?').' ('.($m['size'] ?? 0).' bytes)', array_slice($data['members'] ?? [], 0, 200))],
                ];
                break;

            default:
                $details['panels'] = [];
        }

        return $details;
    }

    /**
     * Human-readable disclosure of what may be sent to the AI provider.
     * Shown before the first transmission; upload alone never consents.
     */
    public static function disclosure(ProjectArtifact $artifact): string
    {
        $parts = [
            'file name, format, size, and a SHA-256 prefix (never raw bytes)',
            'extraction warnings and limits',
        ];

        if ($artifact->format === ProjectArtifact::FORMAT_ELF) {
            $parts[] = 'ELF header, section/symbol counts, library names, bounded strings, endpoint candidates';
        } elseif (in_array($artifact->format, [ProjectArtifact::FORMAT_PCAP, ProjectArtifact::FORMAT_PCAPNG], true)) {
            $parts[] = 'packet counts, time range, flows, DNS questions/answers, redacted HTTP/TLS metadata';
        } else {
            $parts[] = 'format evidence, headers, section/member lists, bounded strings, and — after local analysis — disassembly excerpts, function lists, and references';
        }

        $parts[] = 'credentials, cookies, tokens, and private keys are redacted where recognizable — but redaction cannot catch every secret';

        return implode('; ', $parts).'.';
    }
}
