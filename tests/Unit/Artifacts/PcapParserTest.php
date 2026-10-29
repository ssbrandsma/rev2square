<?php

namespace Tests\Unit\Artifacts;

use App\Services\Artifacts\PcapParser;
use PHPUnit\Framework\TestCase;

class PcapParserTest extends TestCase
{
    private static function bytes(string $name): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/Artifacts/files/'.$name);
    }

    public function test_pcap_summary_and_stable_numbering(): void
    {
        $r = PcapParser::parse(self::bytes('capture-mixed.pcap'));

        $this->assertTrue($r['ok']);
        $this->assertSame('pcap', $r['format']);
        $this->assertSame(17, $r['data']['packet_count']);
        $this->assertSame(range(1, 17), array_column($r['data']['packets'], 'n'));
        $this->assertSame(1720000000, (int) $r['data']['time_range']['start']);
        $this->assertSame(1720000016, (int) $r['data']['time_range']['end']);
        $this->assertContains('192.168.1.10', $r['data']['endpoints']);
        $this->assertArrayHasKey('TCP', $r['data']['protocols']);
        $this->assertArrayHasKey('UDP', $r['data']['protocols']);
    }

    public function test_dns_correlation(): void
    {
        $r = PcapParser::parse(self::bytes('capture-mixed.pcap'));
        $dns = $r['data']['dns'];

        $names = array_column($dns['queries'], 'name');
        $this->assertContains('example.com', $names);
        $this->assertSame(['93.184.216.34'], $dns['resolutions']['example.com']['addresses']);
        $this->assertSame(2, $dns['resolutions']['example.com']['first_packet']);
    }

    public function test_flows_record_initiator_and_deterministic_bounds(): void
    {
        $r = PcapParser::parse(self::bytes('capture-mixed.pcap'));

        $http = collect($r['data']['flows'])->firstWhere('first_packet', 3);
        $this->assertSame('192.168.1.10:40000', $http['initiator']);
        $this->assertSame('93.184.216.34:80', $http['responder']);
        $this->assertSame(7, $http['last_packet']);
        $this->assertSame(1720000002, (int) $http['first_ts']);
        $this->assertSame(1720000006, (int) $http['last_ts']);

        // The refused flow's lexicographic order (service first) must not
        // move the initiator: the client sent the lone SYN.
        $refused = collect($r['data']['flows'])->firstWhere('outcome', 'refused_or_reset');
        $this->assertSame('10.0.0.5:1883', $refused['a']);
        $this->assertSame('192.168.1.10:40001', $refused['initiator']);

        // UDP flows have no SYN evidence: no initiator asserted.
        $dns = collect($r['data']['flows'])->firstWhere('proto', 'UDP');
        $this->assertArrayNotHasKey('initiator', $dns);
    }

    public function test_tcp_outcomes_are_qualified(): void
    {
        $r = PcapParser::parse(self::bytes('capture-mixed.pcap'));
        $tcp = $r['data']['tcp'];

        $this->assertSame(4, $tcp['attempts']);
        $this->assertSame(2, $tcp['completed']);
        $this->assertSame(1, $tcp['refused']);
        $this->assertSame(1, $tcp['incomplete']);

        $outcomes = [];
        foreach ($r['data']['flows'] as $flow) {
            if ($flow['proto'] === 'TCP') {
                $outcomes[] = $flow['outcome'];
            }
        }
        $this->assertContains('completed_handshake', $outcomes);
        $this->assertContains('refused_or_reset', $outcomes);
        $this->assertContains('incomplete', $outcomes);
    }

    public function test_http_is_redacted_metadata_only(): void
    {
        $r = PcapParser::parse(self::bytes('capture-mixed.pcap'));

        $get = null;
        foreach ($r['data']['http'] as $h) {
            if (($h['method'] ?? null) === 'GET') {
                $get = $h;
            }
        }

        $this->assertNotNull($get);
        $this->assertSame('/api/data?token=[REDACTED]', $get['target']);
        $this->assertSame('example.com', $get['headers']['host']);
        $this->assertArrayNotHasKey('cookie', $get['headers'] ?? []);
        $this->assertStringNotContainsString('SYNTHETIC-COOKIE', json_encode($r['data']));
        $this->assertStringNotContainsString('SYNTHETIC-BEARER', json_encode($r['data']));
        $this->assertStringNotContainsString('SYNTHETIC-URL-TOKEN', json_encode($r['data']));
    }

    public function test_tls_handshake_metadata(): void
    {
        $r = PcapParser::parse(self::bytes('capture-mixed.pcap'));

        $this->assertNotSame([], $r['data']['tls']);
        $this->assertSame('tls.example.com', $r['data']['tls'][0]['sni']);
        $this->assertContains('http/1.1', $r['data']['tls'][0]['alpn']);
    }

    public function test_discovery_protocols(): void
    {
        $r = PcapParser::parse(self::bytes('capture-mixed.pcap'));
        $protos = array_column($r['data']['discovery'], 'protocol');

        $this->assertContains('mDNS', $protos);
        $this->assertContains('SSDP', $protos);
    }

    public function test_pcapng_interfaces_precision_and_linktypes(): void
    {
        $r = PcapParser::parse(self::bytes('capture-mixed.pcapng'));

        $this->assertTrue($r['ok']);
        $this->assertSame('pcapng', $r['format']);
        $this->assertSame(4, $r['data']['packet_count']);
        $this->assertSame([1, 1, 101], array_column($r['data']['interfaces'], 'linktype'));
        $this->assertSame('decimal:9', $r['data']['interfaces'][1]['precision']);
        $this->assertEqualsWithDelta(3.0, $r['data']['packets'][2]['ts'], 0.001);
        $this->assertSame('unsupported_linktype', $r['data']['packets'][3]['note']);
        $this->assertContains('unsupported_linktype_present', $r['data']['warnings']);
    }

    public function test_truncated_capture_warns(): void
    {
        $r = PcapParser::parse(self::bytes('capture-truncated.pcap'));

        $this->assertTrue($r['ok']);
        $this->assertContains('capture_truncated_or_capped', $r['data']['warnings']);
        $this->assertGreaterThan(0, $r['data']['packet_count']);
    }

    public function test_packet_limits_are_reported(): void
    {
        $r = PcapParser::parse(self::bytes('capture-mixed.pcap'), ['max_packets' => 5]);

        $this->assertTrue($r['ok']);
        $this->assertSame(5, $r['data']['packet_count']);
        $this->assertContains('capture_truncated_or_capped', $r['data']['warnings']);
    }

    public function test_unknown_magic_is_rejected(): void
    {
        $r = PcapParser::parse(self::bytes('not-elf.bin'));

        $this->assertFalse($r['ok']);
        $this->assertSame('not_capture', $r['error']);
    }
}
