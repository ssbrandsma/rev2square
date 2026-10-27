<?php

return [

    'github' => [
        // Outbound requests are restricted to this host; redirects elsewhere are rejected.
        'host' => 'api.github.com',
        'timeout' => (int) env('GITHUB_TIMEOUT', 15),
        // Optional token to raise the rate limit (60/hr anonymous). Never logged.
        'token' => env('GITHUB_TOKEN'),
        'user_agent' => 'rev2square-importer',

        // Ingestion budgets (WP2).
        'max_files' => (int) env('GITHUB_MAX_FILES', 50),
        'max_file_bytes' => (int) env('GITHUB_MAX_FILE_BYTES', 100000),
        'max_total_bytes' => (int) env('GITHUB_MAX_TOTAL_BYTES', 500000),
        // Approximate tokens (chars/4) the evidence package may hold for WP3.
        'token_budget' => (int) env('GITHUB_TOKEN_BUDGET', 100000),
        'max_depth' => 6,

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
