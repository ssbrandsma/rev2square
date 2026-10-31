<?php

// Project-scoped upload investigation (executables, firmware, captures).
// Raw artifacts stay on the private artifacts disk; only bounded,
// redacted extraction snapshots are ever model-facing (and only after
// explicit per-conversation consent).
return [

    // Master switch for upload investigation.
    'enabled' => (bool) env('ARTIFACTS_ENABLED', true),

    // Single upload cap (bytes). Default 64 MiB.
    'max_upload_bytes' => (int) env('ARTIFACTS_MAX_UPLOAD_BYTES', 64 * 1024 * 1024),

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

    // Max single archive member staged for analysis (bytes, expanded).
    // Analysis sweep caps bound the actual work; this bounds staging.
    'binary_max_archive_member_bytes' => (int) env('BINARY_MAX_ARCHIVE_MEMBER_BYTES', 32 * 1024 * 1024),

    // Worker capability snapshots older than this refuse subprocess
    // dispatch until refreshed (hourly scheduler + artisan command).
    'binary_worker_snapshot_ttl_hours' => (int) env('BINARY_WORKER_SNAPSHOT_TTL_HOURS', 24),

    // Fixed external-backend paths. Empty = backend not installed.
    // These are read-only probes: the app never installs or downloads.
    // NOTE: the *_bin paths below are legacy local-subprocess settings
    // and no longer route traffic. Tool binaries live only inside the
    // isolated runner images; the coordinator reaches them through the
    // spool (see docs/analysis-spool-deployment.md).
    'ghidra_headless' => (string) env('GHIDRA_HEADLESS', ''),
    'jadx_bin' => (string) env('JADX_BIN', ''),
    'ilspy_bin' => (string) env('ILSPY_BIN', ''),
    'objdump_bin' => (string) env('OBJDUMP_BIN', '/usr/bin/objdump'),

    // objdump subprocess bounds (each run additionally respects the
    // shared instruction/xref/evidence caps above).
    'binary_objdump_timeout_seconds' => (int) env('BINARY_OBJDUMP_TIMEOUT', 60),
    'binary_objdump_max_output_bytes' => (int) env('BINARY_OBJDUMP_MAX_OUTPUT_BYTES', 8 * 1024 * 1024),

    // ---- Isolated analysis spool (coordinator <-> runner boundary) ----
    //
    // The coordinator writes job specs to the spool dir; isolated
    // runner containers (read-only spool mount) execute them and write
    // results to the results dir. In compose these are named volumes
    // shared only between the coordinator and the runners.
    'binary_spool_dir' => (string) env('BINARY_SPOOL_DIR', storage_path('app/analysis-spool')),
    'binary_results_dir' => (string) env('BINARY_RESULTS_DIR', storage_path('app/analysis-results')),

    // Wall-clock budget the runner enforces per sandboxed run
    // (seconds). The queue job timeout is this plus a grace margin,
    // and the analysis queue connection's retry_after must exceed the
    // job timeout (see config/queue.php analysis-database).
    'binary_spool_wall_seconds' => (int) env('BINARY_SPOOL_WALL_SECONDS', 480),

    // Capability-probe round-trip budget per runner (seconds). This
    // shared default is the version-ping deadline: it exceeds the
    // runner's version subprocess bound (VERSION_TIMEOUT_SECONDS)
    // plus claim/collection margin. Adapters whose probe runs more
    // override: ObjdumpAdapter (75s: version + architecture discovery),
    // JadxAdapter (150s: version + fixture). Those bounds live in
    // docker/analysis/runner.py; keep them aligned (protocol §1).
    // Queue-job timeouts derive from these budgets.
    'binary_spool_probe_seconds' => (int) env('BINARY_SPOOL_PROBE_SECONDS', 45),

    // Grace after the runner wall clock during which the coordinator
    // keeps waiting for a terminal runner status before giving up.
    'binary_spool_grace_seconds' => (int) env('BINARY_SPOOL_GRACE_SECONDS', 30),

    // Dedicated queue + connection for deterministic analysis. NEVER
    // run these jobs on the general-purpose workers: only the
    // restricted analysis coordinator serves this queue.
    'binary_analysis_queue' => (string) env('BINARY_ANALYSIS_QUEUE', 'analysis'),
    'binary_analysis_connection' => (string) env('BINARY_ANALYSIS_CONNECTION', 'analysis-database'),

];
