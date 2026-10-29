<?php

namespace App\Services\Artifacts;

/**
 * Candidate endpoint spotting inside extracted strings.
 *
 * Every match is a CANDIDATE: a string inside firmware does not prove
 * the device contacts that endpoint or that the code path is reachable.
 * Matches carry the string's file offset so each candidate resolves to
 * an exact byte location.
 */
final class EndpointFinder
{
    /**
     * @param  list<array{offset: int, length: int, value: string}>  $strings
     * @return list<array{value: string, kind: string, offset: int}>
     */
    public static function find(array $strings, int $max = 200): array
    {
        $out = [];
        $seen = [];
        foreach ($strings as $s) {
            foreach (self::scan($s['value']) as $match) {
                $key = $match['kind'].':'.$match['value'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = ['value' => $match['value'], 'kind' => $match['kind'], 'offset' => $s['offset']];
                if (count($out) >= $max) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /** @return list<array{value: string, kind: string}> */
    private static function scan(string $value): array
    {
        $out = [];

        if (preg_match_all('~\bhttps?://[^\s"\']{3,120}~i', $value, $m)) {
            foreach ($m[0] as $url) {
                $out[] = ['value' => rtrim($url, '.,);'), 'kind' => 'url'];
            }
        }

        if (preg_match_all('~\b(?:mqtt|mqtts|coap|coaps|ws|wss|ftp|ntp|syslog)://[^\s"\']{1,120}~i', $value, $m)) {
            foreach ($m[0] as $url) {
                $out[] = ['value' => rtrim($url, '.,);'), 'kind' => 'protocol_url'];
            }
        }

        if (preg_match_all('~\b(?:\d{1,3}\.){3}\d{1,3}(?::\d{1,5})?\b~', $value, $m)) {
            foreach ($m[0] as $ip) {
                $addr = explode(':', $ip)[0];
                $octets = array_map(intval(...), explode('.', $addr));
                if (max($octets) <= 255) {
                    $out[] = ['value' => $ip, 'kind' => 'ipv4'];
                }
            }
        }

        if (preg_match_all('~\b(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:com|org|net|io|dev|local|lan|home|arpa)(?::\d{1,5})?\b~i', $value, $m)) {
            foreach ($m[0] as $host) {
                $out[] = ['value' => strtolower($host), 'kind' => 'hostname'];
            }
        }

        if (preg_match_all('~\b(?:/api/|mqtt|coap|xmpp|ssdp|mdns|upnp|websocket)\b~i', $value, $m)) {
            foreach (array_unique(array_map(strtolower(...), $m[0])) as $proto) {
                $out[] = ['value' => $proto, 'kind' => 'protocol_hint'];
            }
        }

        return $out;
    }
}
