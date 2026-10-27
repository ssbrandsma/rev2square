<?php

// Meta Model API integration facts verified against official docs
// (https://dev.meta.ai/docs): base URL, Bearer MODEL_API_KEY auth,
// muse-spark-1.3 model ID, Chat Completions + response_format json_schema.
return [

    'default' => env('AI_PROVIDER', 'meta'),

    'meta' => [
        'base_url' => env('META_API_BASE', 'https://api.meta.ai/v1'),
        'api_key' => env('MODEL_API_KEY'),
        'model' => env('META_MODEL', 'muse-spark-1.3'),
        'timeout' => (int) env('META_TIMEOUT', 300),
        // A complete v1.0 report over ~35 evidence files needs well over
        // 8000 output tokens (observed truncation at the 8000 cap).
        'max_completion_tokens' => (int) env('META_MAX_TOKENS', 16000),
    ],

    // Approximate input-token budget (chars/4) for evidence selection per analysis.
    'analysis_token_budget' => (int) env('AI_ANALYSIS_TOKEN_BUDGET', 60000),

    // Per-user, per-project analysis throttle.
    'rate_limit' => [
        'max_attempts' => 10,
        'per_minutes' => 60,
    ],

];
