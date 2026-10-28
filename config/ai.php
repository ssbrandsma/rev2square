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

    // Max output tokens requested for one architecture snapshot.
    'architecture_token_budget' => (int) env('AI_ARCHITECTURE_TOKEN_BUDGET', 12000),

    // Per-user, per-project analysis throttle.
    'rate_limit' => [
        'max_attempts' => 10,
        'per_minutes' => 60,
    ],

    // Revival Agent turn limits (see AgentTurnRunner).
    'agent_token_budget' => (int) env('AI_AGENT_TOKEN_BUDGET', 30000),
    'agent_turn_seconds' => (int) env('AI_AGENT_TURN_SECONDS', 60),
    'agent_max_tool_calls' => (int) env('AI_AGENT_MAX_TOOL_CALLS', 6),
    'agent_max_identical_calls' => (int) env('AI_AGENT_MAX_IDENTICAL_CALLS', 2),

];
