<?php

namespace App\Services\Agent;

use App\Models\ProjectArtifact;
use App\Services\Artifacts\ArtifactEvidence;
use App\Services\Artifacts\Redactor;

/**
 * The Revival Agent's read-only upload-evidence tools.
 *
 * All reads serve the cached, redacted extraction snapshot — never raw
 * bytes. Every call re-checks the project policy, the conversation's
 * explicit artifact consent, and the server-side artifact allowlist.
 * Model-supplied ids select rows; they never widen access.
 */
final class ArtifactTools
{
    public const LIST = 'list_artifacts';

    public const SEARCH_ELF = 'search_elf_strings';

    public const READ_ELF = 'read_elf_metadata';

    public const SEARCH_CAPTURE = 'search_capture';

    public const LIST_FLOWS = 'list_capture_flows';

    public const READ_PACKET = 'read_packet';

    public const COMPARE = 'compare_evidence';

    public const MAX_QUERY = 200;

    public const MAX_LIMIT = 8;

    /** @return list<string> */
    public static function names(): array
    {
        return [self::LIST, self::SEARCH_ELF, self::READ_ELF, self::SEARCH_CAPTURE, self::LIST_FLOWS, self::READ_PACKET, self::COMPARE];
    }

    /** @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}> */
    public static function definitions(): array
    {
        $artifactId = ['type' => 'integer', 'description' => 'Artifact id from list_artifacts.'];

        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => self::LIST,
                    'description' => 'List the uploaded firmware/capture artifacts selected for this investigation with their extraction coverage and state.',
                    'parameters' => ['type' => 'object', 'properties' => new \stdClass],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => self::SEARCH_ELF,
                    'description' => 'Search ELF strings, symbols, and endpoint candidates by keyword. Returns matches with file offsets.',
                    'parameters' => [
                        'type' => 'object',
                        'required' => ['artifact_id', 'query'],
                        'properties' => [
                            'artifact_id' => $artifactId,
                            'query' => ['type' => 'string', 'description' => 'Keywords, max 200 chars.'],
                            'limit' => ['type' => 'integer', 'description' => 'Max results, 1-8. Default 5.'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => self::READ_ELF,
                    'description' => 'Read bounded ELF metadata: header, sections, segments, symbols, dynamic entries, or endpoint candidates.',
                    'parameters' => [
                        'type' => 'object',
                        'required' => ['artifact_id', 'section'],
                        'properties' => [
                            'artifact_id' => $artifactId,
                            'section' => ['type' => 'string', 'description' => 'One of: header, sections, segments, symbols, dynamic, endpoints.'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => self::SEARCH_CAPTURE,
                    'description' => 'Search capture DNS questions, resolutions, endpoints, and HTTP/TLS metadata by keyword.',
                    'parameters' => [
                        'type' => 'object',
                        'required' => ['artifact_id', 'query'],
                        'properties' => [
                            'artifact_id' => $artifactId,
                            'query' => ['type' => 'string', 'description' => 'Hostname, address, or keyword, max 200 chars.'],
                            'limit' => ['type' => 'integer', 'description' => 'Max results, 1-8. Default 5.'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => self::LIST_FLOWS,
                    'description' => 'List capture conversations/flows with packet and byte counts, optionally filtered by address or port.',
                    'parameters' => [
                        'type' => 'object',
                        'required' => ['artifact_id'],
                        'properties' => [
                            'artifact_id' => $artifactId,
                            'filter' => ['type' => 'string', 'description' => 'Optional address or port substring.'],
                            'limit' => ['type' => 'integer', 'description' => 'Max results, 1-8. Default 5.'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => self::READ_PACKET,
                    'description' => 'Read bounded metadata for one packet by its number: addresses, ports, protocol, DNS/HTTP/TLS fields where visible.',
                    'parameters' => [
                        'type' => 'object',
                        'required' => ['artifact_id', 'packet'],
                        'properties' => [
                            'artifact_id' => $artifactId,
                            'packet' => ['type' => 'integer', 'description' => 'Packet/frame number (starts at 1).'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => self::COMPARE,
                    'description' => 'Compare a hostname, address, or keyword across the selected artifacts: exact matches in firmware strings/endpoints and capture DNS/flows.',
                    'parameters' => [
                        'type' => 'object',
                        'required' => ['query'],
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'Hostname, address, or keyword, max 200 chars.'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $args
     */
    public static function execute(string $name, ?array $args, ToolContext $context): ToolResult
    {
        if (! in_array($name, self::names(), true)) {
            return ToolResult::error('unknown_tool', "Unknown tool '{$name}'.");
        }

        if (! $context->user->can('view', $context->project)) {
            return ToolResult::error('forbidden', 'You no longer have access to this project.');
        }

        if (! $context->allowsArtifacts()) {
            return ToolResult::error('forbidden', 'This conversation has no consented artifact evidence. The user must confirm the disclosure before uploaded files can be investigated.');
        }

        if ($name === self::LIST) {
            return self::list($context);
        }

        if ($name === self::COMPARE) {
            return self::compare($args ?? [], $context);
        }

        $artifactId = $args['artifact_id'] ?? null;
        if (! is_numeric($artifactId)) {
            return ToolResult::error('invalid_arguments', 'artifact_id is required.');
        }

        $artifact = self::resolve((int) $artifactId, $context);
        if ($artifact === null) {
            return ToolResult::error('forbidden', 'Unknown artifact, or it is not selected for this investigation.');
        }

        if (! $artifact->isUsable()) {
            $message = $artifact->state === ProjectArtifact::STATE_FAILED
                ? 'Extraction failed: '.($artifact->failure_message ?? 'unknown error').' The user can retry from the artifact page.'
                : 'Extraction is still running for this file. Report what is ready and ask the user to retry shortly.';

            return ToolResult::error($artifact->state === ProjectArtifact::STATE_FAILED ? 'extraction_failed' : 'extraction_pending', $message);
        }

        $snapshot = $artifact->readSnapshot();
        if ($snapshot === null) {
            return ToolResult::error('extraction_pending', 'Extraction output is unavailable. Ask the user to retry shortly.');
        }

        $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];

        return match ($name) {
            self::SEARCH_ELF => self::searchElf($artifact, $data, $args ?? []),
            self::READ_ELF => self::readElf($artifact, $data, $args ?? []),
            self::SEARCH_CAPTURE => self::searchCapture($artifact, $data, $args ?? []),
            self::LIST_FLOWS => self::listFlows($artifact, $data, $args ?? []),
            self::READ_PACKET => self::readPacket($artifact, $data, $args ?? []),
            default => ToolResult::error('unknown_tool', "Unknown tool '{$name}'."),
        };
    }

    private static function resolve(int $id, ToolContext $context): ?ProjectArtifact
    {
        if (! in_array($id, $context->artifactIds, true)) {
            return null;
        }

        $artifact = ProjectArtifact::find($id);

        return $artifact !== null && $artifact->project_id === $context->project->id ? $artifact : null;
    }

    private static function list(ToolContext $context): ToolResult
    {
        $artifacts = ProjectArtifact::whereIn('id', $context->artifactIds)
            ->where('project_id', $context->project->id)
            ->orderBy('id')
            ->get();

        $items = [];
        $seen = [];
        foreach ($artifacts as $artifact) {
            $items[] = ArtifactEvidence::coverage($artifact);
            $seen[] = $artifact->id;
        }

        return ToolResult::ok([
            'artifacts' => $items,
            'citation' => 'Cite artifact findings with [A{id}] markers, e.g. [A'.$artifacts->first()?->id.'].',
        ], [], $seen);
    }

    /** @param  array<string, mixed>  $args */
    private static function searchElf(ProjectArtifact $artifact, array $data, array $args): ToolResult
    {
        if ($artifact->format !== ProjectArtifact::FORMAT_ELF) {
            return ToolResult::error('wrong_format', 'This artifact is a capture, not firmware. Use search_capture instead.');
        }

        $query = self::query($args);
        if ($query === null) {
            return ToolResult::error('invalid_arguments', 'query is required (1-'.self::MAX_QUERY.' chars).');
        }
        $limit = self::limit($args);

        $terms = self::terms($query);
        $matches = [];

        foreach ($data['strings'] ?? [] as $string) {
            if (! is_array($string) || count($matches) >= $limit * 4) {
                break;
            }
            $value = (string) ($string['value'] ?? '');
            if (self::hits($value, $terms)) {
                $hex = sprintf('0x%x', (int) ($string['offset'] ?? 0));
                $matches[] = [
                    'kind' => 'string',
                    'value' => Redactor::redactText(substr($value, 0, 256)),
                    'file_offset' => $hex,
                    'offset_kind' => 'file_offset',
                    'viewer_anchor' => '#offset-'.$hex,
                    'citation' => "[A{$artifact->id}]",
                ];
            }
        }

        foreach ($data['symbols'] ?? [] as $symbol) {
            if (! is_array($symbol) || count($matches) >= $limit * 4) {
                break;
            }
            $name = (string) ($symbol['name'] ?? '');
            if ($name !== '' && self::hits($name, $terms)) {
                $matches[] = [
                    'kind' => 'symbol',
                    'name' => substr($name, 0, 128),
                    'value' => $symbol['value'] ?? null,
                    'binding' => $symbol['binding'] ?? null,
                    'citation' => "[A{$artifact->id}]",
                ];
            }
        }

        foreach ($data['endpoints'] ?? [] as $endpoint) {
            if (! is_array($endpoint) || count($matches) >= $limit * 4) {
                break;
            }
            $value = (string) ($endpoint['value'] ?? '');
            if ($value !== '' && self::hits($value, $terms)) {
                $hex = isset($endpoint['offset']) ? sprintf('0x%x', (int) $endpoint['offset']) : null;
                $matches[] = [
                    'kind' => 'endpoint_candidate',
                    'endpoint_kind' => $endpoint['kind'] ?? 'endpoint',
                    'value' => Redactor::redactUrl(substr($value, 0, 256)),
                    'file_offset' => $hex,
                    'viewer_anchor' => $hex !== null ? '#offset-'.$hex : null,
                    'note' => 'Candidate only: a string in firmware does not prove the device contacts it.',
                    'citation' => "[A{$artifact->id}]",
                ];
            }
        }

        $matches = array_slice($matches, 0, $limit);

        return ToolResult::ok([
            'artifact_id' => $artifact->id,
            'matches' => $matches,
            'hint' => $matches === [] ? 'No matches in extracted strings/symbols/endpoints. Missing symbols are normal for stripped binaries — say so rather than claiming absence.' : null,
            'warnings' => array_slice($data['warnings'] ?? [], 0, 5),
        ], [], [$artifact->id]);
    }

    /** @param  array<string, mixed>  $args */
    private static function readElf(ProjectArtifact $artifact, array $data, array $args): ToolResult
    {
        if ($artifact->format !== ProjectArtifact::FORMAT_ELF) {
            return ToolResult::error('wrong_format', 'This artifact is a capture, not firmware.');
        }

        $section = $args['section'] ?? null;
        if (! in_array($section, ['header', 'sections', 'segments', 'symbols', 'dynamic', 'endpoints'], true)) {
            return ToolResult::error('invalid_arguments', 'section must be one of: header, sections, segments, symbols, dynamic, endpoints.');
        }

        $payload = match ($section) {
            'header' => [
                'class' => $data['class'] ?? null,
                'endian' => $data['endian'] ?? null,
                'machine' => $data['machine'] ?? null,
                'object_type' => $data['object_type'] ?? null,
                'entry_point' => $data['entry_point'] ?? null,
                'address_note' => 'entry_point is a virtual address, not a file offset.',
            ],
            'sections' => ['sections' => array_slice($data['sections'] ?? [], 0, 40)],
            'segments' => ['segments' => array_slice($data['segments'] ?? [], 0, 20)],
            'symbols' => ['symbols' => array_slice($data['symbols'] ?? [], 0, 40), 'stats' => $data['symbol_stats'] ?? null],
            'dynamic' => $data['dynamic'] ?? ['present' => false],
            'endpoints' => ['endpoints' => array_slice($data['endpoints'] ?? [], 0, 40), 'note' => 'Candidates only, not proof of contact.'],
        };

        return ToolResult::ok([
            'artifact_id' => $artifact->id,
            'section' => $section,
            'data' => self::redactDeep($payload),
            'warnings' => array_slice($data['warnings'] ?? [], 0, 5),
            'citation' => "[A{$artifact->id}]",
        ], [], [$artifact->id]);
    }

    /** @param  array<string, mixed>  $args */
    private static function searchCapture(ProjectArtifact $artifact, array $data, array $args): ToolResult
    {
        if ($artifact->format === ProjectArtifact::FORMAT_ELF) {
            return ToolResult::error('wrong_format', 'This artifact is firmware, not a capture. Use search_elf_strings instead.');
        }

        $query = self::query($args);
        if ($query === null) {
            return ToolResult::error('invalid_arguments', 'query is required (1-'.self::MAX_QUERY.' chars).');
        }
        $limit = self::limit($args);
        $terms = self::terms($query);
        $matches = [];

        foreach ($data['dns']['queries'] ?? [] as $dns) {
            if (! is_array($dns) || count($matches) >= $limit * 3) {
                break;
            }
            if (self::hits((string) ($dns['name'] ?? ''), $terms)) {
                $matches[] = [
                    'kind' => 'dns_query',
                    'name' => $dns['name'] ?? null,
                    'type' => $dns['type'] ?? null,
                    'packet' => $dns['packet'] ?? null,
                    'is_response' => $dns['is_response'] ?? null,
                    'viewer_anchor' => isset($dns['packet']) ? '#packet-'.(int) $dns['packet'] : null,
                    'observation_note' => 'A DNS request does not prove a successful connection.',
                    'citation' => "[A{$artifact->id}]",
                ];
            }
        }

        foreach ($data['dns']['resolutions'] ?? [] as $resolution) {
            if (! is_array($resolution) || count($matches) >= $limit * 3) {
                break;
            }
            $haystack = (string) ($resolution['name'] ?? '').' '.implode(' ', (array) ($resolution['addresses'] ?? []));
            if (self::hits($haystack, $terms)) {
                $matches[] = [
                    'kind' => 'dns_resolution',
                    'name' => $resolution['name'] ?? null,
                    'addresses' => array_slice((array) ($resolution['addresses'] ?? []), 0, 5),
                    'first_packet' => $resolution['first_packet'] ?? null,
                    'citation' => "[A{$artifact->id}]",
                ];
            }
        }

        foreach ($data['endpoints'] ?? [] as $endpoint) {
            if (count($matches) >= $limit * 3) {
                break;
            }
            if (is_string($endpoint) && self::hits($endpoint, $terms)) {
                $matches[] = ['kind' => 'endpoint', 'address' => $endpoint, 'citation' => "[A{$artifact->id}]"];
            }
        }

        foreach ($data['http'] ?? [] as $http) {
            if (! is_array($http) || count($matches) >= $limit * 3) {
                break;
            }
            // Parser shape: requests carry method + redacted target,
            // responses carry status; host lives in headers.host.
            $host = is_array($http['headers'] ?? null) ? ($http['headers']['host'] ?? '') : '';
            $haystack = (string) ($http['method'] ?? '').' '.(string) ($http['target'] ?? '').' '.(string) $host.' '.(string) ($http['status'] ?? '');
            if (self::hits($haystack, $terms)) {
                $matches[] = [
                    'kind' => 'http',
                    'http_kind' => isset($http['method']) ? 'request' : 'response',
                    'method' => $http['method'] ?? null,
                    'host' => $host !== '' ? $host : null,
                    'target' => isset($http['target']) ? Redactor::redactUrl((string) $http['target']) : null,
                    'status' => $http['status'] ?? null,
                    'packet' => $http['packet'] ?? null,
                    'ts' => $http['ts'] ?? null,
                    'viewer_anchor' => isset($http['packet']) ? '#packet-'.(int) $http['packet'] : null,
                    'citation' => "[A{$artifact->id}]",
                ];
            }
        }

        foreach ($data['tls'] ?? [] as $tls) {
            if (! is_array($tls) || count($matches) >= $limit * 3) {
                break;
            }
            $haystack = (string) ($tls['sni'] ?? '').' '.implode(' ', (array) ($tls['alpn'] ?? []));
            if (self::hits($haystack, $terms)) {
                $matches[] = [
                    'kind' => 'tls',
                    'sni' => $tls['sni'] ?? null,
                    'alpn' => $tls['alpn'] ?? null,
                    'packet' => $tls['packet'] ?? null,
                    'note' => 'Handshake metadata only; encrypted payloads are not readable.',
                    'citation' => "[A{$artifact->id}]",
                ];
            }
        }

        $matches = array_slice($matches, 0, $limit);

        return ToolResult::ok([
            'artifact_id' => $artifact->id,
            'matches' => $matches,
            'hint' => $matches === [] ? 'No matches in DNS/endpoints/HTTP/TLS metadata. Encrypted or unparsed traffic may still exist — say what was searched.' : null,
        ], [], [$artifact->id]);
    }

    /** @param  array<string, mixed>  $args */
    private static function listFlows(ProjectArtifact $artifact, array $data, array $args): ToolResult
    {
        if ($artifact->format === ProjectArtifact::FORMAT_ELF) {
            return ToolResult::error('wrong_format', 'This artifact is firmware, not a capture.');
        }

        $filter = isset($args['filter']) && is_string($args['filter']) ? strtolower(substr($args['filter'], 0, 64)) : null;
        $limit = self::limit($args);
        $flows = [];

        foreach ($data['flows'] ?? [] as $flow) {
            if (! is_array($flow)) {
                continue;
            }
            $haystack = strtolower((string) ($flow['a'] ?? '').' '.(string) ($flow['b'] ?? '').' '.(string) ($flow['initiator'] ?? ''));
            if ($filter !== null && $filter !== '' && ! str_contains($haystack, $filter)) {
                continue;
            }
            $presented = ArtifactEvidence::presentFlow($flow);
            $presented['direction_note'] = is_string($flow['initiator'] ?? null)
                ? 'Initiator sent the first SYN-only packet.'
                : 'No lone SYN observed; do not describe either side as the client or server.';
            $flows[] = $presented;
            if (count($flows) >= $limit) {
                break;
            }
        }

        return ToolResult::ok([
            'artifact_id' => $artifact->id,
            'flows' => $flows,
            'tcp_summary' => $data['tcp'] ?? null,
            'flows_truncated' => $data['flows_truncated'] ?? false,
            'observation_note' => 'Endpoints are an unordered pair unless initiator/responder is stated from SYN evidence. Repeated SYNs do not establish the cause of failure; a destination IP alone does not prove service ownership.',
            'citation' => "[A{$artifact->id}]",
        ], [], [$artifact->id]);
    }

    /** @param  array<string, mixed>  $args */
    private static function readPacket(ProjectArtifact $artifact, array $data, array $args): ToolResult
    {
        if ($artifact->format === ProjectArtifact::FORMAT_ELF) {
            return ToolResult::error('wrong_format', 'This artifact is firmware, not a capture.');
        }

        $packet = $args['packet'] ?? null;
        if (! is_numeric($packet) || (int) $packet < 1) {
            return ToolResult::error('invalid_arguments', 'packet must be a frame number starting at 1.');
        }

        $wanted = (int) $packet;
        foreach ($data['packets'] ?? [] as $meta) {
            if (is_array($meta) && ($meta['n'] ?? null) === $wanted) {
                $payload = [
                    'artifact_id' => $artifact->id,
                    'packet' => self::redactDeep($meta),
                    'viewer_anchor' => '#packet-'.$wanted,
                    'citation' => "[A{$artifact->id}]",
                ];

                foreach ($data['http'] ?? [] as $http) {
                    if (is_array($http) && ($http['packet'] ?? null) === $wanted) {
                        $payload['http'] = ArtifactEvidence::presentHttp($http);

                        break;
                    }
                }

                foreach ($data['tls'] ?? [] as $tls) {
                    if (is_array($tls) && ($tls['packet'] ?? null) === $wanted) {
                        $payload['tls'] = self::redactDeep($tls);

                        break;
                    }
                }

                $dns = [];
                foreach ($data['dns']['queries'] ?? [] as $query) {
                    if (is_array($query) && ($query['packet'] ?? null) === $wanted) {
                        $dns[] = $query;
                    }
                }
                if ($dns !== []) {
                    $payload['dns'] = $dns;
                }

                return ToolResult::ok($payload, [], [$artifact->id]);
            }
        }

        return ToolResult::error('not_found', "Packet {$wanted} is not in the extracted metadata (packet_count=".($data['packet_count'] ?? 0).').');
    }

    /** @param  array<string, mixed>  $args */
    private static function compare(array $args, ToolContext $context): ToolResult
    {
        $query = self::query($args);
        if ($query === null) {
            return ToolResult::error('invalid_arguments', 'query is required (1-'.self::MAX_QUERY.' chars).');
        }

        $terms = self::terms($query);
        $artifacts = ProjectArtifact::whereIn('id', $context->artifactIds)
            ->where('project_id', $context->project->id)
            ->orderBy('id')
            ->get();

        $findings = [];
        $seen = [];
        foreach ($artifacts as $artifact) {
            if (! $artifact->isUsable()) {
                $findings[] = ['artifact_id' => $artifact->id, 'name' => $artifact->original_name, 'state' => $artifact->state, 'note' => 'Not ready; excluded from comparison.'];

                continue;
            }

            $snapshot = $artifact->readSnapshot();
            $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
            $seen[] = $artifact->id;

            if ($artifact->format === ProjectArtifact::FORMAT_ELF) {
                $hits = [];
                foreach ($data['strings'] ?? [] as $string) {
                    if (is_array($string) && self::hits((string) ($string['value'] ?? ''), $terms)) {
                        $hits[] = ['kind' => 'string', 'value' => Redactor::redactText(substr((string) $string['value'], 0, 200)), 'file_offset' => sprintf('0x%x', (int) ($string['offset'] ?? 0))];
                        if (count($hits) >= 3) {
                            break;
                        }
                    }
                }
                foreach ($data['endpoints'] ?? [] as $endpoint) {
                    if (is_array($endpoint) && self::hits((string) ($endpoint['value'] ?? ''), $terms)) {
                        $hits[] = ['kind' => 'endpoint_candidate', 'value' => Redactor::redactUrl(substr((string) $endpoint['value'], 0, 200))];
                        if (count($hits) >= 5) {
                            break;
                        }
                    }
                }
                $findings[] = ['artifact_id' => $artifact->id, 'name' => $artifact->original_name, 'format' => 'elf', 'matches' => $hits, 'citation' => "[A{$artifact->id}]"];
            } elseif (in_array($artifact->format, [ProjectArtifact::FORMAT_PCAP, ProjectArtifact::FORMAT_PCAPNG], true)) {
                $hits = [];
                foreach ($data['dns']['queries'] ?? [] as $dns) {
                    if (is_array($dns) && self::hits((string) ($dns['name'] ?? ''), $terms)) {
                        $hits[] = ['kind' => 'dns_query', 'name' => $dns['name'], 'packet' => $dns['packet'] ?? null];
                        if (count($hits) >= 5) {
                            break;
                        }
                    }
                }
                foreach ($data['dns']['resolutions'] ?? [] as $resolution) {
                    if (is_array($resolution) && self::hits((string) ($resolution['name'] ?? '').' '.implode(' ', (array) ($resolution['addresses'] ?? [])), $terms)) {
                        $hits[] = ['kind' => 'dns_resolution', 'name' => $resolution['name'], 'addresses' => array_slice((array) ($resolution['addresses'] ?? []), 0, 3)];
                        if (count($hits) >= 5) {
                            break;
                        }
                    }
                }
                foreach ($data['http'] ?? [] as $http) {
                    if (! is_array($http) || count($hits) >= 5) {
                        break;
                    }
                    $presented = ArtifactEvidence::presentHttp($http);
                    $haystack = (string) ($presented['method'] ?? '').' '.(string) ($presented['target'] ?? '').' '.(string) ($presented['host'] ?? '').' '.(string) ($presented['status'] ?? '');
                    if (self::hits($haystack, $terms)) {
                        $hits[] = ['kind' => 'http'] + $presented;
                    }
                }
                foreach ($data['tls'] ?? [] as $tls) {
                    if (! is_array($tls) || count($hits) >= 5) {
                        break;
                    }
                    if (self::hits((string) ($tls['sni'] ?? ''), $terms)) {
                        $hits[] = ['kind' => 'tls', 'sni' => $tls['sni'] ?? null, 'packet' => $tls['packet'] ?? null];
                    }
                }
                $findings[] = ['artifact_id' => $artifact->id, 'name' => $artifact->original_name, 'format' => $artifact->format, 'matches' => $hits, 'citation' => "[A{$artifact->id}]"];
            } else {
                $findings[] = [
                    'artifact_id' => $artifact->id,
                    'name' => $artifact->original_name,
                    'format' => $artifact->format,
                    'matches' => self::binaryHits($artifact, $data, $terms),
                    'citation' => "[A{$artifact->id}]",
                ];
            }
        }

        return ToolResult::ok([
            'query' => $query,
            'findings' => $findings,
            'interpretation_rule' => 'A matching string is correlation, not proof of runtime execution or causality. Use search_evidence/read_evidence to check repository sources for the same term.',
        ], [], $seen);
    }

    /**
     * Cross-artifact term matching for binary formats: flat string
     * pools (PE/Mach-O/DEX), APK manifest/DEX/symbol surfaces, PRG
     * BASIC lines, archive members. Correlates; never proves.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $terms
     * @return list<array<string, mixed>>
     */
    private static function binaryHits(ProjectArtifact $artifact, array $data, array $terms): array
    {
        $hits = [];
        $push = function (array $hit) use (&$hits): void {
            if (count($hits) < 5) {
                $hits[] = $hit;
            }
        };

        foreach ($data['strings'] ?? [] as $string) {
            $value = is_array($string) ? (string) ($string['value'] ?? '') : '';
            if ($value !== '' && self::hits($value, $terms)) {
                $offset = is_array($string) && isset($string['offset']) ? sprintf('0x%x', (int) $string['offset']) : null;
                $push(['kind' => 'string', 'value' => Redactor::redactText(substr($value, 0, 200)), 'file_offset' => $offset]);
            }
        }

        foreach (($data['manifest']['strings'] ?? []) as $value) {
            if (is_string($value) && self::hits($value, $terms)) {
                $push(['kind' => 'manifest_string', 'value' => Redactor::redactText(substr($value, 0, 200))]);
            }
        }

        foreach (($data['dexes'] ?? []) as $dex) {
            foreach (($dex['classes'] ?? []) as $class) {
                $type = is_array($class) ? (string) ($class['type'] ?? '') : '';
                if ($type !== '' && self::hits($type, $terms)) {
                    $push(['kind' => 'dex_class', 'value' => substr($type, 0, 200), 'member' => $dex['name'] ?? null]);
                }
            }
            foreach (array_slice($dex['methods'] ?? [], 0, 200) as $method) {
                if (is_string($method) && self::hits($method, $terms)) {
                    $push(['kind' => 'dex_method', 'value' => substr($method, 0, 200), 'member' => $dex['name'] ?? null]);
                }
            }
        }

        foreach (($data['native_libs'] ?? []) as $lib) {
            foreach (($lib['symbols'] ?? []) as $symbol) {
                if (is_string($symbol) && $symbol !== '' && self::hits($symbol, $terms)) {
                    $push(['kind' => 'native_symbol', 'value' => substr($symbol, 0, 200), 'member' => $lib['name'] ?? null]);
                }
            }
        }

        foreach (($data['basic_lines'] ?? []) as $line) {
            $text = is_array($line) ? (string) ($line['text'] ?? '') : '';
            if ($text !== '' && self::hits($text, $terms)) {
                $push(['kind' => 'basic_line', 'line' => $line['number'] ?? null, 'value' => Redactor::redactText(substr($text, 0, 200))]);
            }
        }

        foreach (($data['members'] ?? []) as $member) {
            $name = is_array($member) ? (string) ($member['name'] ?? '') : '';
            if ($name !== '' && self::hits($name, $terms)) {
                $push(['kind' => 'archive_member', 'value' => substr($name, 0, 200)]);
            }
        }

        foreach (($data['imports'] ?? []) as $module) {
            $dll = is_array($module) ? (string) ($module['dll'] ?? '') : '';
            if ($dll !== '' && self::hits($dll, $terms)) {
                $push(['kind' => 'import_module', 'value' => substr($dll, 0, 200)]);
            }
            foreach (array_slice(is_array($module) ? ($module['functions'] ?? []) : [], 0, 50) as $function) {
                if (is_string($function) && self::hits($function, $terms)) {
                    $push(['kind' => 'import_function', 'value' => substr($function, 0, 200), 'module' => $dll]);
                }
            }
        }

        return $hits;
    }

    /** @param  array<string, mixed>  $args */
    private static function query(array $args): ?string
    {
        $query = $args['query'] ?? null;

        return is_string($query) && trim($query) !== '' && strlen($query) <= self::MAX_QUERY ? $query : null;
    }

    /** @param  array<string, mixed>  $args */
    private static function limit(array $args): int
    {
        $limit = $args['limit'] ?? 5;

        return is_numeric($limit) ? max(1, min(self::MAX_LIMIT, (int) $limit)) : 5;
    }

    /** @return list<string> */
    private static function terms(string $query): array
    {
        preg_match_all('/[a-z0-9.\-]{2,}/i', strtolower($query), $m);

        return array_slice(array_values(array_unique($m[0])), 0, 8);
    }

    /** @param  list<string>  $terms */
    private static function hits(string $haystack, array $terms): bool
    {
        $lower = strtolower($haystack);
        foreach ($terms as $term) {
            if (str_contains($lower, $term)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $payload */
    private static function redactDeep(array $payload): array
    {
        array_walk_recursive($payload, function (&$value): void {
            if (is_string($value)) {
                $value = Redactor::redactText($value);
            }
        });

        return $payload;
    }
}
