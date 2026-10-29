<?php

/**
 * Synthetic fixture generator for upload investigation tests.
 *
 * Builds minimal valid ELF and PCAP/PCAPNG binaries by hand (no
 * toolchain needed) plus malformed variants. All contents are
 * synthetic: example.com-style hostnames, documentation IPs, and
 * fake credentials clearly marked SYNTHETIC. Checksums are zeroed;
 * the parsers under test do not validate checksums (documented).
 *
 * Usage: php tests/Fixtures/Artifacts/generate.php
 * Output: tests/Fixtures/Artifacts/files/
 */

declare(strict_types=1);

$root = __DIR__.'/files';
@mkdir($root, 0777, true);

final class ElfBuilder
{
    public function __construct(
        private readonly bool $is64,
        private readonly bool $be,
        private readonly int $machine,
    ) {}

    private function v16(int $v): string
    {
        return $this->be ? pack('n', $v) : pack('v', $v);
    }

    private function v32(int $v): string
    {
        return $this->be ? pack('N', $v) : pack('V', $v);
    }

    private function v64(int $v): string
    {
        return $this->be ? pack('J', $v) : pack('P', $v);
    }

    /** @param array{static?: bool, stripped?: bool, bad_section?: bool} $opts */
    public function build(array $opts = []): string
    {
        $static = $opts['static'] ?? false;
        $stripped = $opts['stripped'] ?? false;

        $rodata = "https://updates.example.com/fw\x00"
            ."mqtt://broker.local:1883\x00"
            ."api_key=SYNTHETIC-TEST-KEY-12345\x00"
            ."device-serial-0042\x00";
        $text = str_repeat($this->is64 ? "\x1f\x20\x03\xd5" : "\x00\x00\xa0\xe1", 16);
        $shstrtab = "\x00.text\x00.rodata\x00.shstrtab\x00.strtab\x00.symtab\x00.dynamic\x00.dynstr\x00.dynsym\x00";
        $strtab = "\x00main\x00puts\x00";
        $dynstr = "\x00libc.so.6\x00fixture.so.1\x00";

        // Section contents in fixed index order.
        $blobs = [1 => $text, 2 => $rodata, 3 => $shstrtab, 4 => $strtab];
        $sections = [];
        $sections[] = [0, 0, 0, 0, 0, 0, 0, 0]; // NULL
        $sections[] = [1, 1, 6, 0x1000, 0, strlen($text), 0, 0]; // .text
        $sections[] = [7, 1, 2, 0x2000, 0, strlen($rodata), 0, 0]; // .rodata
        $sections[] = [15, 3, 0, 0, 0, strlen($shstrtab), 0, 0]; // .shstrtab
        $sections[] = [25, 3, 0, 0, 0, strlen($strtab), 0, 0]; // .strtab

        if (! $stripped) {
            $symtab = $this->sym(0, 0, 0, 0, 0, 0)
                .$this->sym(1, 0x1000, 64, 0x12, 0, 1)
                .$this->sym(6, 0, 0, 0x12, 0, 0);
            $blobs[5] = $symtab;
            $sections[] = [33, 2, 0, 0, 0, strlen($symtab), 4, 1]; // .symtab
        }

        if (! $static) {
            $dynamic = $this->dyn(1, 1).$this->dyn(14, 11).$this->dyn(0, 0);
            $dynsym = $this->sym(0, 0, 0, 0, 0, 0).$this->sym(1, 0, 0, 0x12, 0, 0);
            $dynIdx = count($sections);
            $blobs[$dynIdx] = $dynamic;
            $sections[] = [41, 6, 2, 0x3000, 0, strlen($dynamic), $dynIdx + 1, 0];
            $blobs[$dynIdx + 1] = $dynstr;
            $sections[] = [50, 3, 2, 0x3000 + strlen($dynamic), 0, strlen($dynstr), 0, 0];
            $blobs[$dynIdx + 2] = $dynsym;
            $sections[] = [58, 11, 2, 0, 0, strlen($dynsym), $dynIdx + 1, 1];
        }

        $ehsize = $this->is64 ? 64 : 52;
        $phentsize = $this->is64 ? 56 : 32;
        $phnum = $static ? 2 : 3;
        $off = $ehsize + $phnum * $phentsize;

        $body = '';
        foreach ($blobs as $idx => $blob) {
            while (($off + strlen($body)) % 4 !== 0) {
                $body .= "\x00";
            }
            $sections[$idx][4] = $off + strlen($body);
            $body .= $blob;
        }

        $shoff = $off + strlen($body);
        $shentsize = $this->is64 ? 64 : 40;

        if ($opts['bad_section'] ?? false) {
            $sections[1][4] = 0xFFFFFF00; // .text offset beyond EOF
        }

        $ident = "\x7fELF".chr($this->is64 ? 2 : 1).chr($this->be ? 2 : 1)."\x01\x00".str_repeat("\x00", 8);
        $bin = $ident
            .$this->v16(2).$this->v16($this->machine).$this->v32(1)
            .($this->is64 ? $this->v64(0x1000).$this->v64($ehsize).$this->v64($shoff) : $this->v32(0x1000).$this->v32($ehsize).$this->v32($shoff))
            .$this->v32(0)
            .$this->v16($ehsize).$this->v16($phentsize).$this->v16($phnum)
            .$this->v16($shentsize).$this->v16(count($sections)).$this->v16(3);

        // LOAD rx (headers+text), LOAD r (rodata), DYNAMIC?
        $bin .= $this->phdr(1, $ehsize, 0x1000, $sections[1][4] + $sections[1][5] - $ehsize, 5);
        $bin .= $this->phdr(1, $sections[2][4], 0x2000, $sections[2][5], 4);
        if (! $static) {
            $bin .= $this->phdr(2, $sections[$dynIdx][4], 0x3000, $sections[$dynIdx][5], 4);
        }

        $bin .= $body;

        foreach ($sections as $s) {
            $bin .= $this->shdr($s[0], $s[1], $s[2], $s[3], $s[4], $s[5], $s[6], $s[7]);
        }

        return $bin;
    }

    private function phdr(int $type, int $offset, int $vaddr, int $size, int $flags): string
    {
        if ($this->is64) {
            return $this->v32($type).$this->v32($flags).$this->v64($offset).$this->v64($vaddr)
                .$this->v64($vaddr).$this->v64($size).$this->v64($size).$this->v64(0x1000);
        }

        return $this->v32($type).$this->v32($offset).$this->v32($vaddr).$this->v32($vaddr)
            .$this->v32($size).$this->v32($size).$this->v32($flags).$this->v32(0x1000);
    }

    private function shdr(int $name, int $type, int $flags, int $addr, int $offset, int $size, int $link, int $info): string
    {
        if ($this->is64) {
            return $this->v32($name).$this->v32($type).$this->v64($flags).$this->v64($addr)
                .$this->v64($offset).$this->v64($size).$this->v32($link).$this->v32($info)
                .$this->v64(4).$this->v64($type === 2 || $type === 11 ? 24 : 0);
        }

        return $this->v32($name).$this->v32($type).$this->v32($flags).$this->v32($addr)
            .$this->v32($offset).$this->v32($size).$this->v32($link).$this->v32($info)
            .$this->v32(4).$this->v32($type === 2 || $type === 11 ? 16 : 0);
    }

    private function sym(int $name, int $value, int $size, int $info, int $other, int $shndx): string
    {
        if ($this->is64) {
            return $this->v32($name).chr($info).chr($other).$this->v16($shndx).$this->v64($value).$this->v64($size);
        }

        return $this->v32($name).$this->v32($value).$this->v32($size).chr($info).chr($other).$this->v16($shndx);
    }

    private function dyn(int $tag, int $val): string
    {
        return $this->is64 ? $this->v64($tag).$this->v64($val) : $this->v32($tag).$this->v32($val);
    }
}

final class NetBuilder
{
    public static function eth(string $payload, string $type = "\x08\x00"): string
    {
        return "\x02\x00\x00\x00\x00\x02\x02\x00\x00\x00\x00\x01".$type.$payload;
    }

    public static function ipv4(string $src, string $dst, int $proto, string $payload): string
    {
        $h = "\x45\x00".pack('n', 20 + strlen($payload))."\x00\x00\x40\x00\x40".chr($proto)."\x00\x00"
            .inet_pton($src).inet_pton($dst);

        return $h.$payload;
    }

    public static function udp(int $sport, int $dport, string $payload): string
    {
        return pack('nnn', $sport, $dport, 8 + strlen($payload))."\x00\x00".$payload;
    }

    public static function tcp(int $sport, int $dport, int $seq, int $ack, int $flags, string $payload = ''): string
    {
        return pack('nnNN', $sport, $dport, $seq, $ack)."\x50".chr($flags).pack('nnn', 65535, 0, 0).$payload;
    }

    public static function dnsQuery(int $txid, string $name): string
    {
        $qname = '';
        foreach (explode('.', $name) as $label) {
            $qname .= chr(strlen($label)).$label;
        }

        return pack('nnnnnn', $txid, 0x0100, 1, 0, 0, 0).$qname."\x00".pack('nn', 1, 1);
    }

    public static function dnsResponse(int $txid, string $name, string $ip): string
    {
        $qname = '';
        foreach (explode('.', $name) as $label) {
            $qname .= chr(strlen($label)).$label;
        }
        $q = $qname."\x00".pack('nn', 1, 1);

        return pack('nnnnnn', $txid, 0x8180, 1, 1, 0, 0).$q."\xc0\x0c".pack('nnNn', 1, 1, 300, 4).inet_pton($ip);
    }

    public static function clientHello(string $sni): string
    {
        $host = pack('n', strlen($sni)).$sni;
        $sniExt = pack('n', 0).pack('n', strlen($host) + 3).pack('n', strlen($host) + 1)."\x00".$host;
        $alpn = pack('n', 16).pack('n', 11).pack('n', 9)."\x08http/1.1";
        $exts = $sniExt.$alpn;
        $hello = "\x03\x03".str_repeat("\x42", 32)."\x00".pack('n', 2)."\x13\x01"."\x01\x00"
            .pack('n', strlen($exts)).$exts;
        $hs = "\x01".substr(pack('N', strlen($hello)), 1).$hello;

        return "\x16\x03\x01".pack('n', strlen($hs)).$hs;
    }
}

// ---------------- ELF fixtures ----------------
$elfs = [
    'elf32-arm.elf' => (new ElfBuilder(false, false, 40))->build(),
    'elf64-aarch64.elf' => (new ElfBuilder(true, false, 183))->build(),
    'elf32-x86.elf' => (new ElfBuilder(false, false, 3))->build(),
    'elf64-x86_64.elf' => (new ElfBuilder(true, false, 62))->build(),
    'elf32-arm-be.elf' => (new ElfBuilder(false, true, 40))->build(),
    'elf64-mips.elf' => (new ElfBuilder(true, false, 8))->build(),
    'elf64-static-stripped.elf' => (new ElfBuilder(true, false, 62))->build(['static' => true, 'stripped' => true]),
    'elf32-badsection.elf' => (new ElfBuilder(false, false, 3))->build(['bad_section' => true]),
];
foreach ($elfs as $name => $bin) {
    file_put_contents($root.'/'.$name, $bin);
}
file_put_contents($root.'/elf64-truncated.elf', substr($elfs['elf64-x86_64.elf'], 0, 56));
file_put_contents($root.'/not-elf.bin', "just some text, definitely not an executable\n");

// ---------------- PCAP fixture ----------------
$dev = '192.168.1.10';
$pkts = [];
$t = 1720000000;
$add = function (string $frame) use (&$pkts, &$t) {
    $pkts[] = [$t, $frame];
    $t += 1;
};

$dnsQ = NetBuilder::dnsQuery(0x1234, 'example.com');
$add(NetBuilder::eth(NetBuilder::ipv4($dev, '8.8.8.8', 17, NetBuilder::udp(50000, 53, $dnsQ))));
$dnsR = NetBuilder::dnsResponse(0x1234, 'example.com', '93.184.216.34');
$add(NetBuilder::eth(NetBuilder::ipv4('8.8.8.8', $dev, 17, NetBuilder::udp(53, 50000, $dnsR))));

$add(NetBuilder::eth(NetBuilder::ipv4($dev, '93.184.216.34', 6, NetBuilder::tcp(40000, 80, 1000, 0, 0x02))));
$add(NetBuilder::eth(NetBuilder::ipv4('93.184.216.34', $dev, 6, NetBuilder::tcp(80, 40000, 5000, 1001, 0x12))));
$add(NetBuilder::eth(NetBuilder::ipv4($dev, '93.184.216.34', 6, NetBuilder::tcp(40000, 80, 1001, 5001, 0x10))));
$get = "GET /api/data?token=SYNTHETIC-URL-TOKEN HTTP/1.1\r\nHost: example.com\r\nCookie: session=SYNTHETIC-COOKIE\r\nAuthorization: Bearer SYNTHETIC-BEARER\r\n\r\n";
$add(NetBuilder::eth(NetBuilder::ipv4($dev, '93.184.216.34', 6, NetBuilder::tcp(40000, 80, 1001, 5001, 0x18, $get))));
$resp = "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 2\r\n\r\nok";
$add(NetBuilder::eth(NetBuilder::ipv4('93.184.216.34', $dev, 6, NetBuilder::tcp(80, 40000, 5001, 1100, 0x18, $resp))));

$add(NetBuilder::eth(NetBuilder::ipv4($dev, '10.0.0.5', 6, NetBuilder::tcp(40001, 1883, 2000, 0, 0x02))));
$add(NetBuilder::eth(NetBuilder::ipv4('10.0.0.5', $dev, 6, NetBuilder::tcp(1883, 40001, 0, 2001, 0x14))));

$add(NetBuilder::eth(NetBuilder::ipv4($dev, '10.0.0.6', 6, NetBuilder::tcp(40002, 8883, 3000, 0, 0x02))));
$add(NetBuilder::eth(NetBuilder::ipv4($dev, '10.0.0.6', 6, NetBuilder::tcp(40002, 8883, 3000, 0, 0x02))));

$add(NetBuilder::eth(NetBuilder::ipv4($dev, '93.184.216.34', 6, NetBuilder::tcp(40003, 443, 4000, 0, 0x02))));
$add(NetBuilder::eth(NetBuilder::ipv4('93.184.216.34', $dev, 6, NetBuilder::tcp(443, 40003, 6000, 4001, 0x12))));
$add(NetBuilder::eth(NetBuilder::ipv4($dev, '93.184.216.34', 6, NetBuilder::tcp(40003, 443, 4001, 6001, 0x10))));
$add(NetBuilder::eth(NetBuilder::ipv4($dev, '93.184.216.34', 6, NetBuilder::tcp(40003, 443, 4001, 6001, 0x18, NetBuilder::clientHello('tls.example.com')))));

$mdns = NetBuilder::dnsQuery(0x2222, 'device.local');
$add(NetBuilder::eth(NetBuilder::ipv4($dev, '224.0.0.251', 17, NetBuilder::udp(5353, 5353, $mdns))));
$msearch = "M-SEARCH * HTTP/1.1\r\nHost: 239.255.255.250:1900\r\nST: ssdp:all\r\n\r\n";
$add(NetBuilder::eth(NetBuilder::ipv4($dev, '239.255.255.250', 17, NetBuilder::udp(50001, 1900, $msearch))));

$pcap = "\xd4\xc3\xb2\xa1".pack('v', 2).pack('v', 4).pack('V', 0).pack('V', 0).pack('V', 262144).pack('V', 1);
foreach ($pkts as [$ts, $frame]) {
    $pcap .= pack('V', $ts).pack('V', 0).pack('V', strlen($frame)).pack('V', strlen($frame)).$frame;
}
file_put_contents($root.'/capture-mixed.pcap', $pcap);
file_put_contents($root.'/capture-truncated.pcap', substr($pcap, 0, (int) (strlen($pcap) / 2)));

// ---------------- PCAPNG fixture ----------------
$block = function (int $type, string $body): string {
    $pad = (4 - (strlen($body) % 4)) % 4;
    $total = 12 + strlen($body) + $pad;

    return pack('V', $type).pack('V', $total).$body.str_repeat("\x00", $pad).pack('V', $total);
};
$png = $block(0x0A0D0D0A, "\x1a\x2b\x3c\x4d".pack('v', 1).pack('v', 0).pack('q', -1));
// IDB0: ethernet, default us precision. IDB1: ethernet with ns tsresol. IDB2: unsupported linktype 101.
$png .= $block(1, pack('v', 1).pack('v', 0).pack('V', 262144));
$png .= $block(1, pack('v', 1).pack('v', 0).pack('V', 262144).pack('v', 9).pack('v', 1)."\x09\x00\x00\x00");
$png .= $block(1, pack('v', 101).pack('v', 0).pack('V', 262144));
$epb = function (int $iface, int $tsHigh, int $tsLow, string $frame) use ($block): string {
    return $block(6, pack('V', $iface).pack('V', $tsHigh).pack('V', $tsLow).pack('V', strlen($frame)).pack('V', strlen($frame)).$frame);
};
$png .= $epb(0, 0, 1000000, $pkts[0][1]);
$png .= $epb(0, 0, 2000000, $pkts[1][1]);
$png .= $epb(1, 0, 3000000000, $pkts[2][1]); // ns ticks on IDB1
$png .= $epb(2, 0, 4000000, $pkts[3][1]);
file_put_contents($root.'/capture-mixed.pcapng', $png);

echo 'wrote '.count(glob($root.'/*'))." fixtures to {$root}\n";
