<?php

return [

    'github' => [
        // Outbound requests are restricted to this host; redirects elsewhere are rejected.
        'host' => 'api.github.com',
        'timeout' => (int) env('GITHUB_TIMEOUT', 15),
        // Optional token to raise the rate limit (60/hr anonymous). Never logged.
        'token' => env('GITHUB_TOKEN'),
        'user_agent' => 'rev2square-importer',

        // Ingestion budgets. Inventory (what exists) is tracked separately from
        // evidence (what is downloaded and stored for AI context).
        'max_files' => (int) env('GITHUB_MAX_FILES', 50),
        // Legacy per-file / total stored-evidence caps; kept as fallbacks for
        // the *_bytes overrides below so existing tuned environments keep working.
        'max_file_bytes' => (int) env('GITHUB_MAX_FILE_BYTES', 100000),
        'max_total_bytes' => (int) env('GITHUB_MAX_TOTAL_BYTES', 500000),
        // Approximate tokens (chars/4) the evidence package may hold for WP3.
        'token_budget' => (int) env('GITHUB_TOKEN_BUDGET', 100000),
        'max_depth' => 6,
        // Maximum inventory rows recorded per import (sorted by path, deterministic).
        'max_inventory_entries' => (int) env('GITHUB_MAX_INVENTORY', 5000),
        // Maximum bytes downloaded from the API per import (full file bodies,
        // before partial extraction trims them for storage).
        'max_download_bytes' => (int) env('GITHUB_MAX_DOWNLOAD_BYTES', 2000000),
        // Single-file download ceiling; larger blobs stay inventory-only.
        'max_download_file_bytes' => (int) env('GITHUB_MAX_DOWNLOAD_FILE_BYTES', 1000000),
        // Maximum stored evidence bytes per import (after extraction).
        'max_evidence_bytes' => (int) env(
            'GITHUB_MAX_EVIDENCE_BYTES', (int) env('GITHUB_MAX_TOTAL_BYTES', 500000)
        ),
        // Maximum stored bytes per evidence file; larger files contribute a
        // bounded partial excerpt instead of being skipped outright.
        'max_extraction_bytes' => (int) env(
            'GITHUB_MAX_EXTRACTION_BYTES', (int) env('GITHUB_MAX_FILE_BYTES', 100000)
        ),
        // Maximum bytes per extracted line-range chunk within one file.
        'max_chunk_bytes' => (int) env('GITHUB_MAX_CHUNK_BYTES', 20000),
        // Bounded fallback when the recursive tree response is truncated:
        // at most this many per-subtree fetches are attempted.
        'max_subtree_fetches' => (int) env('GITHUB_MAX_SUBTREE_FETCHES', 20),

        'include_extensions' => [
            'md', 'markdown', 'txt', 'py', 'json', 'yml', 'yaml',
            'xml', 'toml', 'cfg', 'ini',
        ],
        // Basenames (without extension) always eligible regardless of extension.
        'include_basenames' => [
            'license', 'notice', 'changelog', 'contributing', 'readme',
            'makefile', 'dockerfile',
        ],
        'exclude_dirs' => [
            'node_modules', 'vendor', '.git', 'build', 'dist',
            '__pycache__', '.gradle', '.idea', '.vscode',
        ],
    ],

    // Per-user, per-project import throttle (protects the shared GitHub API quota).
    'import_rate_limit' => [
        'max_attempts' => 10,
        'per_minutes' => 60,
    ],

];
