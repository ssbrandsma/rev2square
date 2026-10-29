<?php

namespace App\Services\Binary\Adapters;

/** Capability levels — never conflated with run states. */
final class Capability
{
    public const SUPPORTED = 'supported';

    public const PARTIAL = 'partial';

    public const UNSUPPORTED = 'unsupported';

    public const DEPENDENCY_UNAVAILABLE = 'dependency_unavailable';

    public const CONFIGURATION_REQUIRED = 'configuration_required';

    public const DETECTION = 'detection';

    public const METADATA = 'metadata';

    public const STRINGS = 'strings';

    public const SEGMENTS = 'segments';

    public const DISASSEMBLY = 'disassembly';

    public const FUNCTIONS = 'functions';

    public const XREFS = 'xrefs';

    public const CALLGRAPH = 'callgraph';

    public const DECOMPILATION = 'decompilation';

    public const AI_EXPLAIN = 'ai_explain';

    public const COMPARISON = 'comparison';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::DETECTION, self::METADATA, self::STRINGS, self::SEGMENTS,
            self::DISASSEMBLY, self::FUNCTIONS, self::XREFS, self::CALLGRAPH,
            self::DECOMPILATION, self::AI_EXPLAIN, self::COMPARISON,
        ];
    }

    public static function label(string $capability): string
    {
        return match ($capability) {
            self::DETECTION => 'Format detection',
            self::METADATA => 'Metadata extraction',
            self::STRINGS => 'Strings/resources',
            self::SEGMENTS => 'Memory map / segments',
            self::DISASSEMBLY => 'Disassembly',
            self::FUNCTIONS => 'Function discovery',
            self::XREFS => 'Cross-references',
            self::CALLGRAPH => 'Call graph',
            self::DECOMPILATION => 'Deterministic decompilation',
            self::AI_EXPLAIN => 'AI explanation / tentative pseudocode',
            self::COMPARISON => 'Cross-artifact comparison',
            default => $capability,
        };
    }

    public static function levelLabel(string $level): string
    {
        return match ($level) {
            self::SUPPORTED => 'Supported',
            self::PARTIAL => 'Partial',
            self::UNSUPPORTED => 'Unsupported',
            self::DEPENDENCY_UNAVAILABLE => 'Dependency unavailable',
            self::CONFIGURATION_REQUIRED => 'Configuration required',
            default => $level,
        };
    }
}
