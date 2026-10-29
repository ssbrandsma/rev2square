<?php

// Project-scoped upload investigation (executables, firmware, captures).
// Raw artifacts stay on the private artifacts disk; only bounded,
// redacted extraction snapshots are ever model-facing (and only after
// explicit per-conversation consent).
return [

    // Master switch for upload investigation.
    'enabled' => (bool) env('ARTIFACTS_ENABLED', true),

    // Single upload cap (bytes). Default 8 MiB.
    'max_upload_bytes' => (int) env('ARTIFACTS_MAX_UPLOAD_BYTES', 8 * 1024 * 1024),

    // Max stored artifacts per project (oldest failed rows may be
    // replaced; ready evidence is never auto-deleted).
    'max_artifacts_per_project' => (int) env('ARTIFACTS_MAX_PER_PROJECT', 25),

    // Max extraction snapshot kept per artifact (bytes of JSON).
    'max_snapshot_bytes' => (int) env('ARTIFACTS_MAX_SNAPSHOT_BYTES', 512 * 1024),

    // ELF bounds.
    'elf_max_strings' => (int) env('ARTIFACTS_ELF_MAX_STRINGS', 2000),
    'elf_max_string_bytes' => (int) env('ARTIFACTS_ELF_MAX_STRING_BYTES', 256),
    'elf_max_symbols' => (int) env('ARTIFACTS_ELF_MAX_SYMBOLS', 2000),
    'elf_max_sections' => (int) env('ARTIFACTS_ELF_MAX_SECTIONS', 256),

    // Capture bounds.
    'pcap_max_packets' => (int) env('ARTIFACTS_PCAP_MAX_PACKETS', 5000),
    'pcap_max_flows' => (int) env('ARTIFACTS_PCAP_MAX_FLOWS', 500),
    'pcap_max_dns' => (int) env('ARTIFACTS_PCAP_MAX_DNS', 500),
    'pcap_max_payload_bytes' => (int) env('ARTIFACTS_PCAP_MAX_PAYLOAD_BYTES', 2048),

    // Model-facing bounds per investigation turn context block.
    'context_max_bytes' => (int) env('ARTIFACTS_CONTEXT_MAX_BYTES', 12000),

    // Wall-clock budget per extraction job (seconds). The job also
    // enforces byte budgets while parsing.
    'extraction_timeout_seconds' => (int) env('ARTIFACTS_EXTRACTION_TIMEOUT', 60),

    // Retention notice (days). No automatic deletion is implemented;
    // maintainers delete artifacts explicitly (see delivery report).
    'retention_days' => (int) env('ARTIFACTS_RETENTION_DAYS', 90),

    // ---- Binary analysis (deterministic local backends) ----

    // Max disassembled instructions and swept bytes per analysis run.
    'binary_max_instructions' => (int) env('BINARY_MAX_INSTRUCTIONS', 20000),
    'binary_max_sweep_bytes' => (int) env('BINARY_MAX_SWEEP_BYTES', 1024 * 1024),

    // Max functions and cross-references kept per run.
    'binary_max_functions' => (int) env('BINARY_MAX_FUNCTIONS', 2000),
    'binary_max_xrefs' => (int) env('BINARY_MAX_XREFS', 10000),

    // Max analysis-evidence JSON kept per run (bytes).
    'binary_max_evidence_bytes' => (int) env('BINARY_MAX_EVIDENCE_BYTES', 512 * 1024),

    // Wall-clock budget per analysis job (seconds).
    'binary_timeout_seconds' => (int) env('BINARY_ANALYSIS_TIMEOUT', 120),

    // Fixed external-backend paths. Empty = backend not installed.
    // These are read-only probes: the app never installs or downloads.
    'ghidra_headless' => (string) env('GHIDRA_HEADLESS', ''),
    'jadx_bin' => (string) env('JADX_BIN', ''),
    'ilspy_bin' => (string) env('ILSPY_BIN', ''),

];
