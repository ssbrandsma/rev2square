<?php

namespace App\Services\Artifacts;

/**
 * Deterministic pure-PHP capture inspector for PCAP and PCAPNG.
 *
 * No reassembly, no payload inspection beyond visible metadata, no
 * network access: DNS questions/answers, flow summaries, TCP attempt
 * outcomes, redacted HTTP/TLS handshake metadata and discovery
 * protocols. Encrypted content is reported as unreadable, never
 * "inspected". Packet numbering follows file order, starting at 1.
 */
final class PcapParser
{
    public const NAME = 'pcap-php';

    public const VERSION = '1.0.0';

    /**
     * @param  array{max_packets?: int, max_flows?: int, max_dns?: int, max_payload_bytes?: int}  $limits
     * @return array{ok: bool, format: string, error?: string, data?: array<string, mixed>}
     */
    public static function parse(string $bytes, array $limits = []): array
    {
        $limits = array_merge([
            'max_packets' => 5000,
            'max_flows' => 500,
            'max_dns' => 500,
            'max_payload_bytes' => 2048,
        ], $limits);

        $size = strlen($bytes);
        if ($size < 24) {
            return ['ok' => false, 'format' => 'unknown', 'error' => 'too_small'];
        }

        $magic = substr($bytes, 0, 4);
        if ($magic === "\xd4\xc3\xb2\xa1" || $magic === "\xa1\xb2\xc3\xd4"
            || $magic === "\x4d\x3c\xb2\xa1" || $magic === "\xa1\xb2\x3c\x4d") {
            $r = new PcapReader($bytes, $limits);

            return $r->run();
        }

        if ($magic === "\x0a\x0d\x0d\x0a") {
            $r = new PcapNgReader($bytes, $limits);

            return $r->run();
        }

        return ['ok' => false, 'format' => 'unknown', 'error' => 'not_capture'];
    }

    /**
     * @param  list<array{n: int, ts: float, iface: int, linktype: int, data: string}>  $packets
     * @return array<string, mixed>
     */
    public static function dissect(array $packets, array $limits, string $format, array $interfaces): array
    {
        $d = new PacketDissector($packets, $limits, $format, $interfaces);

        return $d->run();
    }
}

/**
 * Classic PCAP frame reader (micro/nanosecond, either endianness).
 */
final class PcapReader
{
    public function __construct(private readonly string $bytes, private readonly array $limits) {}

    /** @return array{ok: bool, format: string, error?: string, data?: array<string, mixed>} */
    public function run(): array
    {
        $magic = substr($this->bytes, 0, 4);
        $be = $magic === "\xa1\xb2\xc3\xd4" || $magic === "\xa1\xb2\x3c\x4d";
        $nano = $magic === "\x4d\x3c\xb2\xa1" || $magic === "\xa1\xb2\x3c\x4d";
        $u32 = $be ? 'N' : 'V';
        $u16 = $be ? 'n' : 'v';
        $h = unpack("{$u32}magic/{$u16}ver_maj/{$u16}ver_min/{$u32}thiszone/{$u32}sigfigs/{$u32}snaplen/{$u32}network", $this->bytes);
        if ($h === false) {
            return ['ok' => false, 'format' => 'pcap', 'error' => 'bad_global_header'];
        }

        $packets = [];
        $off = 24;
        $size = strlen($this->bytes);
        $n = 0;
        $truncated = false;
        while ($off + 16 <= $size && count($packets) < $this->limits['max_packets']) {
            $r = unpack("{$u32}ts_sec/{$u32}ts_frac/{$u32}incl/{$u32}orig", $this->bytes, $off);
            if ($r === false || $r['incl'] > 10000000) {
                $truncated = true;

                break;
            }
            $off += 16;
            if ($off + $r['incl'] > $size) {
                $truncated = true;

                break;
            }
            $n++;
            $packets[] = [
                'n' => $n,
                'ts' => $r['ts_sec'] + $r['ts_frac'] / ($nano ? 1000000000 : 1000000),
                'iface' => 0,
                'linktype' => $h['network'],
                'data' => substr($this->bytes, $off, $r['incl']),
            ];
            $off += $r['incl'];
        }

        if ($off < $size && count($packets) >= $this->limits['max_packets']) {
            $truncated = true;
        }

        $data = PcapParser::dissect($packets, $this->limits, 'pcap', [
            ['index' => 0, 'linktype' => $h['network'], 'snaplen' => $h['snaplen'], 'precision' => $nano ? 'ns' : 'us'],
        ]);
        if ($truncated) {
            $data['warnings'][] = 'capture_truncated_or_capped';
        }

        return ['ok' => true, 'format' => 'pcap', 'data' => $data];
    }
}

/**
 * PCAPNG block reader (multi-interface, per-interface resolution).
 */
final class PcapNgReader
{
    public function __construct(private readonly string $bytes, private readonly array $limits) {}

    /** @return array{ok: bool, format: string, error?: string, data?: array<string, mixed>} */
    public function run(): array
    {
        $size = strlen($this->bytes);
        $off = 0;
        $be = false;
        $interfaces = [];
        $packets = [];
        $n = 0;
        $truncated = false;

        while ($off + 12 <= $size && count($packets) < $this->limits['max_packets']) {
            $type = $this->u32($off, $be);
            $total = $this->u32($off + 4, $be);
            if ($total < 12 || $off + $total > $size) {
                $truncated = true;

                break;
            }
            $body = substr($this->bytes, $off + 8, $total - 12);

            if ($type === 0x0A0D0D0A) {
                $order = substr($body, 0, 4);
                if ($order === "\x1a\x2b\x3c\x4d") {
                    $be = false;
                } elseif ($order === "\x4d\x3c\x2b\x1a") {
                    $be = true;
                } else {
                    return ['ok' => false, 'format' => 'pcapng', 'error' => 'bad_byte_order'];
                }
            } elseif ($type === 0x00000001) {
                $interfaces[] = $this->interface($body, $be, count($interfaces));
            } elseif ($type === 0x00000006 || $type === 0x00000003) {
                $pkt = $this->packet($body, $be, $type, $interfaces);
                if ($pkt !== null) {
                    $n++;
                    $pkt['n'] = $n;
                    $packets[] = $pkt;
                }
            }

            $off += $total;
        }

        if ($off < $size && count($packets) >= $this->limits['max_packets']) {
            $truncated = true;
        }

        $data = PcapParser::dissect($packets, $this->limits, 'pcapng', $interfaces);
        if ($truncated) {
            $data['warnings'][] = 'capture_truncated_or_capped';
        }

        return ['ok' => true, 'format' => 'pcapng', 'data' => $data];
    }

    /** @return array{index: int, linktype: int, snaplen: int, precision: string} */
    private function interface(string $body, bool $be, int $index): array
    {
        $precision = 'us';
        if (strlen($body) >= 8) {
            $linktype = $be ? unpack('n', $body, 0)[1] : unpack('v', $body, 0)[1];
            $snaplen = $this->u32s($body, 4, $be);
            foreach ($this->options(substr($body, 8), $be) as [$code, $value]) {
                if ($code === 9 && strlen($value) >= 1) {
                    $tsresol = ord($value[0]);
                    $precision = ($tsresol & 0x80) ? 'binary' : 'decimal:'.$tsresol;
                }
            }
        } else {
            $linktype = 0;
            $snaplen = 0;
        }

        return ['index' => $index, 'linktype' => $linktype, 'snaplen' => $snaplen, 'precision' => $precision];
    }

    /** @return array{ts: float, iface: int, linktype: int, data: string}|null */
    private function packet(string $body, bool $be, int $type, array $interfaces): ?array
    {
        if ($type === 0x00000003 && strlen($body) >= 8) {
            $orig = $this->u32s($body, 4, $be);
            $data = substr($body, 8, $orig);
            $linktype = $interfaces[0]['linktype'] ?? 1;

            return ['ts' => 0.0, 'iface' => 0, 'linktype' => $linktype, 'data' => $data];
        }

        if ($type !== 0x00000006 || strlen($body) < 20) {
            return null;
        }
        $iface = $this->u32s($body, 0, $be);
        $tsHigh = $this->u32s($body, 4, $be);
        $tsLow = $this->u32s($body, 8, $be);
        $caplen = $this->u32s($body, 12, $be);
        $data = substr($body, 20, $caplen);
        $linktype = $interfaces[$iface]['linktype'] ?? 1;
        $precision = $interfaces[$iface]['precision'] ?? 'us';
        $ticks = $tsHigh * 4294967296 + $tsLow;
        $ts = str_starts_with($precision, 'decimal:')
            ? $ticks / (10 ** (int) substr($precision, 8))
            : $ticks / 1000000;

        return ['ts' => $ts, 'iface' => $iface, 'linktype' => $linktype, 'data' => $data];
    }

    /** @return list<array{int, string}> */
    private function options(string $body, bool $be): array
    {
        $out = [];
        $off = 0;
        $len = strlen($body);
        while ($off + 4 <= $len && count($out) < 32) {
            $code = $be ? unpack('n', $body, $off)[1] : unpack('v', $body, $off)[1];
            $optLen = $be ? unpack('n', $body, $off + 2)[1] : unpack('v', $body, $off + 2)[1];
            if ($code === 0) {
                break;
            }
            $out[] = [$code, substr($body, $off + 4, $optLen)];
            $off += 4 + $optLen + ((4 - ($optLen % 4)) % 4);
        }

        return $out;
    }

    private function u32(int $off, bool $be): int
    {
        return $be ? unpack('N', $this->bytes, $off)[1] : unpack('V', $this->bytes, $off)[1];
    }

    private function u32s(string $body, int $off, bool $be): int
    {
        if ($off + 4 > strlen($body)) {
            return 0;
        }

        return $be ? unpack('N', $body, $off)[1] : unpack('V', $body, $off)[1];
    }
}

/**
 * Link/network/transport/application dissection over framed packets.
 */
final class PacketDissector
{
    /** @var list<string> */
    private array $warnings = [];

    private bool $unsupportedLink = false;

    public function __construct(
        private readonly array $packets,
        private readonly array $limits,
        private readonly string $format,
        private readonly array $interfaces,
    ) {}

    /** @return array<string, mixed> */
    public function run(): array
    {
        $metas = [];
        $flows = [];
        $dnsQueries = [];
        $resolutions = [];
        $endpoints = [];
        $protos = [];
        $tcp = ['attempts' => 0, 'completed' => 0, 'refused' => 0, 'incomplete' => 0, 'resets' => 0, 'retransmit_indicators' => 0];
        $http = [];
        $tls = [];
        $discovery = [];
        $firstTs = null;
        $lastTs = null;

        foreach ($this->packets as $pkt) {
            $meta = $this->dissectOne($pkt, $flows, $dnsQueries, $resolutions, $http, $tls, $discovery, $tcp, $endpoints, $protos);
            if ($meta !== null) {
                $metas[] = $meta;
                if ($pkt['ts'] > 0) {
                    $firstTs = $firstTs === null ? $pkt['ts'] : min($firstTs, $pkt['ts']);
                    $lastTs = $lastTs === null ? $pkt['ts'] : max($lastTs, $pkt['ts']);
                }
            }
        }

        foreach ($flows as $key => &$flow) {
            $this->finalizeFlow($flow, $tcp);
            $flow['key'] = $key;
        }
        unset($flow);

        $flowList = array_values($flows);
        usort($flowList, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);
        $flowTruncated = count($flowList) > $this->limits['max_flows'];
        $flowList = array_slice($flowList, 0, $this->limits['max_flows']);

        if ($this->unsupportedLink) {
            $this->warnings[] = 'unsupported_linktype_present';
        }

        return [
            'packet_count' => count($metas),
            'time_range' => $firstTs === null ? null : ['start' => $firstTs, 'end' => $lastTs],
            'interfaces' => $this->interfaces,
            'endpoints' => array_values($endpoints),
            'protocols' => $protos,
            'packets' => $metas,
            'flows' => $flowList,
            'flows_truncated' => $flowTruncated,
            'dns' => ['queries' => array_slice($dnsQueries, 0, $this->limits['max_dns']), 'resolutions' => $resolutions],
            'tcp' => $tcp,
            'http' => $http,
            'tls' => $tls,
            'discovery' => $discovery,
            'warnings' => $this->warnings,
        ];
    }

    /** @return array<string, mixed>|null */
    private function dissectOne(array $pkt, array &$flows, array &$dnsQueries, array &$resolutions, array &$http, array &$tls, array &$discovery, array &$tcp, array &$endpoints, array &$protos): ?array
    {
        $data = $pkt['data'];
        $link = $pkt['linktype'];

        if ($link === 1) {
            $net = $this->ethernet($data);
        } elseif ($link === 228 || $link === 229 || $link === 12) {
            $net = ['offset' => 0, 'ethertype' => $link === 229 ? 0x86DD : 0x0800];
        } else {
            $this->unsupportedLink = true;

            return [
                'n' => $pkt['n'], 'ts' => $pkt['ts'], 'iface' => $pkt['iface'],
                'linktype' => $link, 'note' => 'unsupported_linktype', 'len' => strlen($data),
            ];
        }

        if ($net === null) {
            return ['n' => $pkt['n'], 'ts' => $pkt['ts'], 'iface' => $pkt['iface'], 'linktype' => $link, 'note' => 'truncated_link', 'len' => strlen($data)];
        }

        $meta = [
            'n' => $pkt['n'], 'ts' => $pkt['ts'], 'iface' => $pkt['iface'],
            'linktype' => $link, 'len' => strlen($data),
        ];

        if ($net['ethertype'] === 0x0806) {
            $meta['proto'] = 'ARP';

            return $this->countProto($meta, $protos);
        }

        $ip = $net['ethertype'] === 0x86DD ? $this->ipv6($data, $net['offset']) : $this->ipv4($data, $net['offset']);
        if ($ip === null) {
            $meta['note'] = 'truncated_network';

            return $this->countProto($meta, $protos);
        }

        $meta['src'] = $ip['src'];
        $meta['dst'] = $ip['dst'];
        $endpoints[$ip['src']] = $ip['src'];
        $endpoints[$ip['dst']] = $ip['dst'];

        $transport = $this->transport($data, $ip);
        if ($transport === null) {
            $meta['proto'] = $ip['proto_name'];

            return $this->countProto($meta, $protos);
        }

        $meta = array_merge($meta, $transport['meta']);
        $protos[$meta['proto']] = ($protos[$meta['proto']] ?? 0) + 1;
        $this->flow($flows, $meta, $pkt, $transport, $tcp);

        $payload = $transport['payload'];
        if (($meta['sport'] ?? null) === 53 || ($meta['dport'] ?? null) === 53) {
            $this->dns($payload, $meta, $pkt, $dnsQueries, $resolutions);
        } elseif ((($meta['sport'] ?? null) === 5353 || ($meta['dport'] ?? null) === 5353) && $meta['proto'] === 'UDP') {
            $discovery[] = ['packet' => $pkt['n'], 'protocol' => 'mDNS', 'ts' => $pkt['ts']];
            $this->dns($payload, $meta, $pkt, $dnsQueries, $resolutions);
        } elseif ((($meta['sport'] ?? null) === 1900 || ($meta['dport'] ?? null) === 1900) && $meta['proto'] === 'UDP') {
            $ssdp = HttpInspector::ssdp($payload);
            if ($ssdp !== null) {
                $discovery[] = ['packet' => $pkt['n'], 'protocol' => 'SSDP'] + $ssdp;
            }
        } elseif ($meta['proto'] === 'TCP' && $payload !== '') {
            $req = HttpInspector::request($payload);
            if ($req !== null) {
                $req['packet'] = $pkt['n'];
                $req['ts'] = $pkt['ts'];
                $http[] = $req;
                $meta['http'] = $req['method'] ?? $req['status'] ?? 'seen';
            } else {
                $hello = TlsInspector::clientHello($payload);
                if ($hello !== null) {
                    $hello['packet'] = $pkt['n'];
                    $hello['ts'] = $pkt['ts'];
                    $tls[] = $hello;
                    $meta['tls'] = 'client_hello';
                } elseif (strlen($payload) > 0 && $this->looksEncrypted($payload)) {
                    $meta['tls'] = 'encrypted_or_unknown';
                }
            }
        }

        return $meta;
    }

    /** @return array{offset: int, ethertype: int}|null */
    private function ethernet(string $data): ?array
    {
        if (strlen($data) < 14) {
            return null;
        }
        $type = unpack('n', $data, 12)[1];
        $off = 14;
        if ($type === 0x8100 && strlen($data) >= 18) {
            $type = unpack('n', $data, 16)[1];
            $off = 18;
        }

        return ['offset' => $off, 'ethertype' => $type];
    }

    /** @return array{src: string, dst: string, proto: int, proto_name: string, offset: int}|null */
    private function ipv4(string $data, int $off): ?array
    {
        if ($off + 20 > strlen($data)) {
            return null;
        }
        $ihl = (ord($data[$off]) & 0x0F) * 4;
        if ($ihl < 20 || $off + $ihl > strlen($data)) {
            return null;
        }
        $proto = ord($data[$off + 9]);

        return [
            'src' => implode('.', array_map(ord(...), str_split(substr($data, $off + 12, 4)))),
            'dst' => implode('.', array_map(ord(...), str_split(substr($data, $off + 16, 4)))),
            'proto' => $proto,
            'proto_name' => $this->ipProto($proto),
            'offset' => $off + $ihl,
        ];
    }

    /** @return array{src: string, dst: string, proto: int, proto_name: string, offset: int}|null */
    private function ipv6(string $data, int $off): ?array
    {
        if ($off + 40 > strlen($data)) {
            return null;
        }
        $proto = ord($data[$off + 6]);

        return [
            'src' => inet_ntop(substr($data, $off + 8, 16)) ?: 'invalid',
            'dst' => inet_ntop(substr($data, $off + 24, 16)) ?: 'invalid',
            'proto' => $proto,
            'proto_name' => $this->ipProto($proto),
            'offset' => $off + 40,
        ];
    }

    private function ipProto(int $proto): string
    {
        return match ($proto) {
            1 => 'ICMP', 6 => 'TCP', 17 => 'UDP', 58 => 'ICMPv6', default => 'IPPROTO-'.$proto,
        };
    }

    /** @return array{meta: array<string, mixed>, payload: string}|null */
    private function transport(string $data, array $ip): ?array
    {
        $off = $ip['offset'];
        if ($ip['proto'] === 6) {
            if ($off + 20 > strlen($data)) {
                return null;
            }
            $sport = unpack('n', $data, $off)[1];
            $dport = unpack('n', $data, $off + 2)[1];
            $flags = ord($data[$off + 13]);
            $hlen = (ord($data[$off + 12]) >> 4) * 4;
            if ($hlen < 20 || $off + $hlen > strlen($data)) {
                return null;
            }
            $seq = unpack('N', $data, $off + 4)[1];

            return [
                'meta' => [
                    'proto' => 'TCP', 'sport' => $sport, 'dport' => $dport,
                    'flags' => ['syn' => (bool) ($flags & 2), 'ack' => (bool) ($flags & 16), 'rst' => (bool) ($flags & 4), 'fin' => (bool) ($flags & 1), 'psh' => (bool) ($flags & 8)],
                    'seq' => $seq,
                ],
                'payload' => substr($data, $off + $hlen),
            ];
        }

        if ($ip['proto'] === 17) {
            if ($off + 8 > strlen($data)) {
                return null;
            }

            return [
                'meta' => ['proto' => 'UDP', 'sport' => unpack('n', $data, $off)[1], 'dport' => unpack('n', $data, $off + 2)[1]],
                'payload' => substr($data, $off + 8),
            ];
        }

        return null;
    }

    private function flow(array &$flows, array $meta, array $pkt, array $transport, array &$tcp): void
    {
        if (! isset($meta['sport'])) {
            return;
        }
        $a = $meta['src'].':'.$meta['sport'];
        $b = $meta['dst'].':'.$meta['dport'];
        $fwd = strcmp($a, $b) <= 0;
        $key = $meta['proto'].'|'.($fwd ? $a.'>'.$b : $b.'>'.$a);
        $flow = $flows[$key] ?? [
            'proto' => $meta['proto'], 'a' => $fwd ? $a : $b, 'b' => $fwd ? $b : $a,
            'packets' => 0, 'bytes' => 0, 'packets_ab' => 0, 'packets_ba' => 0,
            'first_packet' => $pkt['n'], 'last_packet' => $pkt['n'],
            'first_ts' => $pkt['ts'], 'last_ts' => $pkt['ts'],
            'syn' => false, 'synack' => false, 'ack' => false, 'rst' => false, 'fin' => false,
            'last_seq' => [], 'retrans' => 0,
        ];

        $flow['packets']++;
        $flow['bytes'] += $meta['len'];
        // Deterministic bounds even if packets arrive out of order.
        $flow['first_packet'] = min($flow['first_packet'], $pkt['n']);
        $flow['last_packet'] = max($flow['last_packet'], $pkt['n']);
        $flow['first_ts'] = min($flow['first_ts'], $pkt['ts']);
        $flow['last_ts'] = max($flow['last_ts'], $pkt['ts']);
        if ($fwd) {
            $flow['packets_ab']++;
        } else {
            $flow['packets_ba']++;
        }

        if ($meta['proto'] === 'TCP') {
            $f = $meta['flags'];
            if ($f['syn'] && ! $f['ack']) {
                $flow['syn'] = true;
                // The side that sent the first lone SYN is the only
                // direction the parser asserts; anything else stays
                // an unordered endpoint pair downstream.
                if (! isset($flow['initiator'])) {
                    $flow['initiator'] = $meta['src'].':'.$meta['sport'];
                    $flow['responder'] = $meta['dst'].':'.$meta['dport'];
                }
            }
            if ($f['syn'] && $f['ack']) {
                $flow['synack'] = true;
            }
            if ($f['ack'] && ! $f['syn']) {
                $flow['ack'] = true;
            }
            if ($f['rst']) {
                $flow['rst'] = true;
                $tcp['resets']++;
            }
            if ($f['fin']) {
                $flow['fin'] = true;
            }
            $payloadLen = strlen($transport['payload']);
            if ($payloadLen > 0) {
                $dir = $fwd ? 'ab' : 'ba';
                $seq = $meta['seq'];
                if (($flow['last_seq'][$dir] ?? null) === $seq.':'.$payloadLen) {
                    $flow['retrans']++;
                    $tcp['retransmit_indicators']++;
                }
                $flow['last_seq'][$dir] = $seq.':'.$payloadLen;
            }
        }

        $flows[$key] = $flow;
    }

    /** @param array<string, mixed> $flow */
    private function finalizeFlow(array &$flow, array &$tcp): void
    {
        unset($flow['last_seq']);
        if ($flow['proto'] !== 'TCP') {
            $flow['outcome'] = 'datagram';

            return;
        }
        $tcp['attempts']++;
        if ($flow['syn'] && $flow['synack'] && $flow['ack']) {
            $flow['outcome'] = 'completed_handshake';
            $tcp['completed']++;
        } elseif ($flow['rst'] && ! $flow['synack']) {
            $flow['outcome'] = 'refused_or_reset';
            $tcp['refused']++;
        } elseif ($flow['rst']) {
            $flow['outcome'] = 'reset_after_progress';
            $tcp['refused']++;
        } else {
            $flow['outcome'] = 'incomplete';
            $tcp['incomplete']++;
        }
    }

    private function dns(string $payload, array $meta, array $pkt, array &$queries, array &$resolutions): void
    {
        $msg = DnsParser::parse($payload);
        if ($msg === null) {
            return;
        }
        foreach ($msg['questions'] as $q) {
            $queries[] = [
                'packet' => $pkt['n'], 'ts' => $pkt['ts'],
                'client' => $msg['is_response'] ? $meta['dst'] : $meta['src'],
                'server' => $msg['is_response'] ? $meta['src'] : $meta['dst'],
                'txid' => $msg['txid'], 'name' => $q['name'], 'type' => $q['type'],
                'is_response' => $msg['is_response'], 'rcode' => $msg['rcode'],
            ];
        }
        foreach ($msg['answers'] as $a) {
            if (! in_array($a['type'], ['A', 'AAAA', 'CNAME'], true)) {
                continue;
            }
            $key = strtolower($a['name']);
            $resolutions[$key] ??= ['name' => $a['name'], 'addresses' => [], 'first_packet' => $pkt['n'], 'first_ts' => $pkt['ts']];
            if (in_array($a['type'], ['A', 'AAAA'], true) && ! in_array($a['value'], $resolutions[$key]['addresses'], true)) {
                $resolutions[$key]['addresses'][] = $a['value'];
            }
        }
    }

    private function looksEncrypted(string $payload): bool
    {
        // TLS application data record header; anything else is unknown,
        // reported as such rather than guessed.
        return strlen($payload) >= 5 && ord($payload[0]) === 23 && ord($payload[1]) === 3;
    }

    /** @return array<string, mixed> */
    private function countProto(array $meta, array &$protos): array
    {
        $name = $meta['proto'] ?? 'OTHER';
        $protos[$name] = ($protos[$name] ?? 0) + 1;
        $meta['proto'] = $name;

        return $meta;
    }
}

/**
 * Minimal DNS message parser (queries + A/AAAA/CNAME answers).
 */
final class DnsParser
{
    /** @return array{txid: int, is_response: bool, rcode: int, questions: list<array{name: string, type: string}>, answers: list<array{name: string, type: string, value: string}>}|null */
    public static function parse(string $payload): ?array
    {
        if (strlen($payload) < 12) {
            return null;
        }
        $h = unpack('ntxid/nflags/nqd/nan/nns/nar', $payload);
        if ($h === false || $h['qd'] > 16 || $h['an'] > 64) {
            return null;
        }
        $off = 12;
        $questions = [];
        for ($i = 0; $i < $h['qd']; $i++) {
            [$name, $off] = self::name($payload, $off);
            if ($name === null || $off + 4 > strlen($payload)) {
                return null;
            }
            $qt = unpack('n', $payload, $off)[1];
            $questions[] = ['name' => $name, 'type' => self::qtype($qt)];
            $off += 4;
        }
        $answers = [];
        for ($i = 0; $i < $h['an']; $i++) {
            [$name, $off] = self::name($payload, $off);
            if ($name === null || $off + 10 > strlen($payload)) {
                break;
            }
            $r = unpack('ntype/nclass/Nttl/nlen', $payload, $off);
            $off += 10;
            if ($off + $r['len'] > strlen($payload)) {
                break;
            }
            $value = '';
            if ($r['type'] === 1 && $r['len'] === 4) {
                $value = implode('.', array_map(ord(...), str_split(substr($payload, $off, 4))));
            } elseif ($r['type'] === 28 && $r['len'] === 16) {
                $value = inet_ntop(substr($payload, $off, 16)) ?: '';
            } elseif ($r['type'] === 5) {
                [$cname] = self::name($payload, $off);
                $value = $cname ?? '';
            }
            $answers[] = ['name' => $name, 'type' => self::qtype($r['type']), 'value' => $value];
            $off += $r['len'];
        }

        return [
            'txid' => $h['txid'],
            'is_response' => (bool) ($h['flags'] & 0x8000),
            'rcode' => $h['flags'] & 0x000F,
            'questions' => $questions,
            'answers' => $answers,
        ];
    }

    /** @return array{?string, int} */
    private static function name(string $payload, int $off): array
    {
        $labels = [];
        $jumps = 0;
        $len = strlen($payload);
        $end = $off;
        while (true) {
            if ($off >= $len || $jumps > 8 || count($labels) > 16) {
                return [null, $end];
            }
            $oct = ord($payload[$off]);
            if ($oct === 0) {
                $end = $end === $off && $jumps === 0 ? $off + 1 : $end;

                return [implode('.', $labels), $end === $off ? $off + 1 : $end];
            }
            if (($oct & 0xC0) === 0xC0) {
                if ($off + 1 >= $len) {
                    return [null, $end];
                }
                if ($jumps === 0) {
                    $end = $off + 2;
                }
                $off = (($oct & 0x3F) << 8) | ord($payload[$off + 1]);
                $jumps++;

                continue;
            }
            if ($off + 1 + $oct > $len) {
                return [null, $end];
            }
            $labels[] = substr($payload, $off + 1, $oct);
            $off += 1 + $oct;
            if ($jumps === 0) {
                $end = $off;
            }
        }
    }

    private static function qtype(int $t): string
    {
        return match ($t) {
            1 => 'A', 2 => 'NS', 5 => 'CNAME', 6 => 'SOA', 12 => 'PTR',
            15 => 'MX', 16 => 'TXT', 28 => 'AAAA', 33 => 'SRV', default => 'TYPE'.$t,
        };
    }
}

/**
 * Visible HTTP metadata only: request line + safe headers, redacted.
 * Bodies are never stored.
 */
final class HttpInspector
{
    /** @return array<string, mixed>|null */
    public static function request(string $payload): ?array
    {
        $head = substr($payload, 0, 2048);
        $end = strpos($head, "\r\n\r\n");
        if ($end === false) {
            return null;
        }
        $lines = explode("\r\n", substr($head, 0, $end));
        $first = $lines[0] ?? '';
        if (preg_match('~^(GET|POST|PUT|DELETE|HEAD|OPTIONS|PATCH|NOTIFY|M-SEARCH) (\S{1,512}) HTTP/(\d\.\d)$~', $first, $m)) {
            $out = ['method' => $m[1], 'target' => Redactor::redactUrl($m[2]), 'version' => $m[3]];
        } elseif (preg_match('~^HTTP/(\d\.\d) (\d{3})(?: (.{0,128}))?$~', $first, $m)) {
            $out = ['version' => $m[1], 'status' => (int) $m[2], 'reason' => $m[3] ?? ''];
        } else {
            return null;
        }
        foreach (array_slice($lines, 1, 32) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));
            if (in_array($name, ['host', 'content-type', 'content-length', 'server', 'location', 'nt', 'nts', 'usn', 'st', 'man'], true)) {
                $out['headers'][$name] = substr($value, 0, 256);
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function ssdp(string $payload): ?array
    {
        $r = self::request($payload);
        if ($r === null || ! in_array($r['method'] ?? '', ['NOTIFY', 'M-SEARCH'], true)) {
            return null;
        }

        return $r;
    }
}

/**
 * TLS ClientHello metadata (SNI/ALPN) when fully present in one segment.
 */
final class TlsInspector
{
    /** @return array<string, mixed>|null */
    public static function clientHello(string $payload): ?array
    {
        if (strlen($payload) < 43 || ord($payload[0]) !== 22 || ord($payload[1]) !== 3) {
            return null;
        }
        $recLen = unpack('n', $payload, 3)[1];
        if (strlen($payload) < 5 + $recLen || ord($payload[5]) !== 1) {
            return null;
        }
        $hsLen = (ord($payload[6]) << 16) | unpack('n', $payload, 7)[1];
        $off = 9;
        if (strlen($payload) < $off + $hsLen) {
            return null;
        }
        $off += 2 + 32;
        if ($off >= strlen($payload)) {
            return null;
        }
        $off += 1 + ord($payload[$off]);
        if ($off + 2 > strlen($payload)) {
            return null;
        }
        $off += 2 + unpack('n', $payload, $off)[1];
        if ($off >= strlen($payload)) {
            return null;
        }
        $off += 1 + ord($payload[$off]);
        if ($off + 2 > strlen($payload)) {
            return null;
        }
        $extLen = unpack('n', $payload, $off)[1];
        $off += 2;
        $end = $off + $extLen;
        if ($end > strlen($payload)) {
            return null;
        }

        $sni = null;
        $alpn = [];
        while ($off + 4 <= $end) {
            $type = unpack('n', $payload, $off)[1];
            $len = unpack('n', $payload, $off + 2)[1];
            $off += 4;
            if ($off + $len > $end) {
                break;
            }
            if ($type === 0 && $sni === null) {
                $sni = self::sni(substr($payload, $off, $len));
            } elseif ($type === 16) {
                $alpn = self::alpn(substr($payload, $off, $len));
            }
            $off += $len;
        }

        if ($sni === null && $alpn === []) {
            return null;
        }

        return ['sni' => $sni, 'alpn' => $alpn, 'version' => 'TLS1.x'];
    }

    private static function sni(string $ext): ?string
    {
        if (strlen($ext) < 5) {
            return null;
        }
        $listLen = unpack('n', $ext, 0)[1];
        if ($listLen + 2 > strlen($ext) || ord($ext[2]) !== 0) {
            return null;
        }
        $nameLen = unpack('n', $ext, 3)[1];
        if (5 + $nameLen > strlen($ext)) {
            return null;
        }
        $name = substr($ext, 5, $nameLen);
        if (! preg_match('~^[A-Za-z0-9.-]{1,253}$~', $name)) {
            return null;
        }

        return strtolower($name);
    }

    /** @return list<string> */
    private static function alpn(string $ext): array
    {
        if (strlen($ext) < 2) {
            return [];
        }
        $total = unpack('n', $ext, 0)[1];
        $off = 2;
        $end = min(2 + $total, strlen($ext));
        $out = [];
        while ($off < $end) {
            $len = ord($ext[$off]);
            $off++;
            if ($off + $len > $end) {
                break;
            }
            $proto = substr($ext, $off, $len);
            if (preg_match('~^[A-Za-z0-9./_-]{1,32}$~', $proto)) {
                $out[] = $proto;
            }
            $off += $len;
        }

        return $out;
    }
}
