<?php

namespace App\Services\Admin;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

/**
 * Database-managed application settings with environment precedence.
 *
 * Rules: a small typed allowlist only (no arbitrary env vars, paths,
 * commands, or URLs); when the mapped environment variable is set, it
 * locks the value and the database row is ignored; secrets are
 * encrypted at rest and never returned to callers that render output.
 */
final class AppSettings
{
    /**
     * @return array<string, array{type: string, default: mixed, env: ?string, config: ?string, min?: int, max?: int, options?: list<string>, help: string, secret?: bool}>
     */
    public static function allowlist(): array
    {
        return [
            'ai.enabled' => [
                'type' => 'bool', 'default' => true, 'env' => 'AI_ENABLED',
                'config' => 'ai.enabled',
                'help' => 'Global AI kill switch. Off blocks every model request with a clear message.',
            ],
            'ai.model' => [
                'type' => 'string', 'default' => 'muse-spark-1.3', 'env' => 'META_MODEL',
                'config' => 'ai.meta.model', 'options' => ['muse-spark-1.3'],
                'help' => 'Provider model identifier. Only models the current integration supports.',
            ],
            'ai.max_completion_tokens' => [
                'type' => 'int', 'default' => 16000, 'env' => 'META_MAX_TOKENS',
                'config' => 'ai.meta.max_completion_tokens', 'min' => 256, 'max' => 64000,
                'help' => 'Per-request output cap, in tokens.',
            ],
            'ai.analysis_token_budget' => [
                'type' => 'int', 'default' => 60000, 'env' => 'AI_ANALYSIS_TOKEN_BUDGET',
                'config' => 'ai.analysis_token_budget', 'min' => 1000, 'max' => 500000,
                'help' => 'Evidence-selection input budget per revival analysis, in tokens.',
            ],
            'ai.architecture_token_budget' => [
                'type' => 'int', 'default' => 12000, 'env' => 'AI_ARCHITECTURE_TOKEN_BUDGET',
                'config' => 'ai.architecture_token_budget', 'min' => 1000, 'max' => 64000,
                'help' => 'Output budget for one architecture snapshot, in tokens.',
            ],
            'ai.agent_token_budget' => [
                'type' => 'int', 'default' => 30000, 'env' => 'AI_AGENT_TOKEN_BUDGET',
                'config' => 'ai.agent_token_budget', 'min' => 1000, 'max' => 200000,
                'help' => 'Token budget per Agent turn.',
            ],
            'ai.default_daily_request_limit' => [
                'type' => 'int', 'default' => 100, 'env' => 'AI_DEFAULT_DAILY_REQUESTS',
                'config' => 'ai.default_daily_request_limit', 'min' => 0, 'max' => 100000,
                'help' => 'Default per-user provider requests per UTC day. Per-user overrides win.',
            ],
            'ai.default_daily_token_limit' => [
                'type' => 'int', 'default' => 200000, 'env' => 'AI_DEFAULT_DAILY_TOKENS',
                'config' => 'ai.default_daily_token_limit', 'min' => 0, 'max' => 100000000,
                'help' => 'Default per-user reconciled tokens per UTC day. Per-user overrides win.',
            ],
            'ai.price_input_per_1k_micros' => [
                'type' => 'int', 'default' => null, 'env' => 'AI_PRICE_INPUT_PER_1K_MICROS',
                'config' => 'ai.price_input_per_1k_micros', 'min' => 0,
                'help' => 'Optional operator-entered input price in micro-USD per 1k tokens (1,000 = $0.001). Empty means costs are unknown, never zero.',
            ],
            'ai.price_output_per_1k_micros' => [
                'type' => 'int', 'default' => null, 'env' => 'AI_PRICE_OUTPUT_PER_1K_MICROS',
                'config' => 'ai.price_output_per_1k_micros', 'min' => 0,
                'help' => 'Optional operator-entered output price in micro-USD per 1k tokens (1,000 = $0.001).',
            ],
            'ai.price_version' => [
                'type' => 'string', 'default' => null, 'env' => 'AI_PRICE_VERSION',
                'config' => 'ai.price_version',
                'help' => 'Label snapshotted onto every estimate (e.g. price-list date).',
            ],
            'ai.daily_cost_cap_micros' => [
                'type' => 'int', 'default' => null, 'env' => 'AI_DAILY_COST_CAP_MICROS',
                'config' => 'ai.daily_cost_cap_micros', 'min' => 0,
                'help' => 'Optional per-user daily spend cap in micro-USD (1,000,000 = $1). Unset = token/request limits only. When set, requests are refused unless pricing is configured, because an unenforceable cap is worse than none.',
            ],
            'artifacts.enabled' => [
                'type' => 'bool', 'default' => true, 'env' => 'ARTIFACTS_ENABLED',
                'config' => 'artifacts.enabled',
                'help' => 'Upload investigation on/off. Toggling on does NOT create OS isolation.',
            ],
            'artifacts.uploads_locked' => [
                'type' => 'bool', 'default' => false, 'env' => 'ARTIFACTS_UPLOADS_LOCKED',
                'config' => 'artifacts.uploads_locked',
                'help' => 'Owner-managed deployment lock: uploads stay off until OS isolation is configured.',
            ],
            'artifacts.max_upload_bytes' => [
                'type' => 'int', 'default' => 67108864, 'env' => 'ARTIFACTS_MAX_UPLOAD_BYTES',
                'config' => 'artifacts.max_upload_bytes', 'min' => 1024, 'max' => 1073741824,
                'help' => 'Single upload cap in bytes (67,108,864 = 64 MiB). Lower values are honored as-is.',
            ],
            'registration.open' => [
                'type' => 'bool', 'default' => true, 'env' => 'REGISTRATION_OPEN',
                'config' => 'auth.registration_open',
                'help' => 'Whether new accounts can register.',
            ],
            'meta.api_key' => [
                'type' => 'secret', 'default' => null, 'env' => 'MODEL_API_KEY',
                'config' => null, 'secret' => true,
                'help' => 'Meta API key. Write-only: never displayed back.',
            ],
        ];
    }

    /**
     * Effective value: environment wins when set, else the database
     * row, else the allowlist default. Secrets are decrypted here and
     * must only be consumed by provider construction, never rendering.
     */
    public static function get(string $key): mixed
    {
        $spec = self::allowlist()[$key] ?? null;

        if ($spec === null) {
            throw new \InvalidArgumentException("Unknown setting '{$key}'.");
        }

        if ($spec['env'] !== null) {
            $env = env($spec['env']);
            if ($env !== null && $env !== '') {
                return self::cast($spec['type'], $env);
            }
        }

        $row = self::row($key);
        if ($row !== null && $row->value !== null && $row->value !== '') {
            if (! empty($spec['secret'])) {
                try {
                    return Crypt::decryptString($row->value);
                } catch (\Throwable) {
                    return null;
                }
            }

            return self::cast($spec['type'], $row->value);
        }

        return $spec['default'];
    }

    public static function isLocked(string $key): bool
    {
        $env = self::allowlist()[$key]['env'] ?? null;

        if ($env === null) {
            return false;
        }

        $value = env($env);

        return $value !== null && $value !== '';
    }

    /** @return 'environment'|'database'|'default' */
    public static function source(string $key): string
    {
        if (self::isLocked($key)) {
            return 'environment';
        }

        $row = self::row($key);

        return $row !== null && $row->value !== null && $row->value !== '' ? 'database' : 'default';
    }

    public static function set(string $key, mixed $value, ?int $updatedBy): void
    {
        $spec = self::allowlist()[$key] ?? null;

        if ($spec === null || ! empty($spec['secret'])) {
            throw new \InvalidArgumentException("Setting '{$key}' is not an ordinary setting.");
        }

        AppSetting::updateOrCreate(['key' => $key], [
            'value' => self::serialize($spec['type'], $value),
            'is_secret' => false,
            'updated_by' => $updatedBy,
        ]);
    }

    public static function setSecret(string $key, string $value, ?int $updatedBy): void
    {
        $spec = self::allowlist()[$key] ?? null;

        if ($spec === null || empty($spec['secret'])) {
            throw new \InvalidArgumentException("Setting '{$key}' is not a secret setting.");
        }

        AppSetting::updateOrCreate(['key' => $key], [
            'value' => Crypt::encryptString($value),
            'is_secret' => true,
            'updated_by' => $updatedBy,
        ]);
    }

    public static function removeSecret(string $key): void
    {
        $spec = self::allowlist()[$key] ?? null;

        if ($spec === null || empty($spec['secret'])) {
            throw new \InvalidArgumentException("Setting '{$key}' is not a secret setting.");
        }

        AppSetting::where('key', $key)->delete();
    }

    /**
     * Apply database values onto runtime config (env-locked keys are
     * skipped). Safe to call before migrations exist: missing tables
     * simply leave file config untouched.
     */
    public static function applyToConfig(): void
    {
        try {
            if (! Schema::hasTable('app_settings')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        foreach (self::allowlist() as $key => $spec) {
            if ($spec['config'] === null || self::isLocked($key)) {
                continue;
            }

            $row = self::row($key);
            if ($row !== null && $row->value !== null && $row->value !== '') {
                config([$spec['config'] => self::cast($spec['type'], $row->value)]);
            }
        }

        // The provider key resolves env-first, database-second.
        if (! self::isLocked('meta.api_key')) {
            $secret = self::get('meta.api_key');
            if (is_string($secret) && $secret !== '') {
                config(['ai.meta.api_key' => $secret]);
            }
        }
    }

    public static function uploadsAllowed(): bool
    {
        if ((bool) self::get('artifacts.uploads_locked')) {
            return false;
        }

        return (bool) self::get('artifacts.enabled');
    }

    private static function row(string $key): ?AppSetting
    {
        try {
            return AppSetting::where('key', $key)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => is_numeric($value) ? (int) $value : null,
            default => is_string($value) ? $value : (string) $value,
        };
    }

    private static function serialize(string $type, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'bool' => $value ? '1' : '0',
            'int' => (string) (int) $value,
            default => substr((string) $value, 0, 500),
        };
    }
}
