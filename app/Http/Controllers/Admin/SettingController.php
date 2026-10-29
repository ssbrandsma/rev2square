<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditEvent;
use App\Models\AppSetting;
use App\Services\Admin\AppSettings;
use App\Services\AI\AIProviderInterface;
use App\Services\AI\AIRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function index(): View
    {
        Gate::authorize('admin');

        $settings = [];
        foreach (AppSettings::allowlist() as $key => $spec) {
            if (! empty($spec['secret'])) {
                continue;
            }
            $row = AppSetting::where('key', $key)->first();
            $settings[$key] = [
                'spec' => $spec,
                'effective' => AppSettings::get($key),
                'source' => AppSettings::source($key),
                'locked' => AppSettings::isLocked($key),
                'updated_at' => $row?->updated_at,
                'updated_by' => $row?->updatedBy?->name,
            ];
        }

        return view('admin.settings.index', ['settings' => $settings]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('admin');

        $input = $request->input('settings', []);
        $applied = [];
        $errors = [];

        foreach (AppSettings::allowlist() as $key => $spec) {
            if (! empty($spec['secret']) || ! array_key_exists($key, $input)) {
                continue;
            }
            if (AppSettings::isLocked($key)) {
                $errors[] = "{$key} is locked by its environment variable and was not changed.";

                continue;
            }

            $raw = $input[$key];
            $validated = $this->validateValue($key, $spec, $raw);
            if ($validated['ok']) {
                $before = AppSettings::source($key) === 'database' ? AppSetting::where('key', $key)->value('value') : null;
                AppSettings::set($key, $validated['value'], $request->user()->id);
                $applied[$key] = ['before' => $before, 'after' => $validated['value']];
            } else {
                $errors[] = $validated['error'];
            }
        }

        // Dependency validation: model must stay within the supported set,
        // and a spend cap without pricing refuses future requests (warn now).
        if (isset($applied['ai.model']) && ! in_array($applied['ai.model']['after'], ['muse-spark-1.3'], true)) {
            AppSetting::where('key', 'ai.model')->delete();
            unset($applied['ai.model']);
            $errors[] = 'ai.model reverted: only supported provider models are allowed.';
        }

        foreach ($applied as $key => $change) {
            AdminAuditEvent::record('setting.updated', $request->user(), null, $request->string('reason')->toString() ?: null, [$key => $change['before']], [$key => $change['after']]);
        }

        $message = count($applied).' setting(s) updated. Changes apply to web requests immediately and to queue workers on their next job (no restart needed).';
        if ($errors !== []) {
            $message .= ' Issues: '.implode(' ', $errors);
        }

        return back()->with($errors !== [] && $applied === [] ? 'error' : 'status', $message);
    }

    public function secrets(): View
    {
        Gate::authorize('admin');

        $row = AppSetting::where('key', 'meta.api_key')->first();

        return view('admin.settings.secrets', [
            'configured' => AppSettings::get('meta.api_key') !== null,
            'source' => AppSettings::source('meta.api_key'),
            'locked' => AppSettings::isLocked('meta.api_key'),
            'updatedAt' => $row?->updated_at,
            'updatedBy' => $row?->updatedBy?->name,
        ]);
    }

    public function updateSecret(Request $request): RedirectResponse
    {
        Gate::authorize('admin');

        if (AppSettings::isLocked('meta.api_key')) {
            return back()->with('error', 'The API key is managed by the MODEL_API_KEY environment variable and cannot be changed here.');
        }

        $data = $request->validate([
            'api_key' => ['nullable', 'string', 'max:500'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        // Blank submission retains the existing key.
        if (($data['api_key'] ?? '') === '') {
            return back()->with('status', 'No key submitted. The existing key was kept unchanged.');
        }

        AppSettings::setSecret('meta.api_key', $data['api_key'], $request->user()->id);
        AdminAuditEvent::recordSecretChange('secret.replaced', $request->user(), [
            'key' => 'meta.api_key', 'source' => 'database',
        ], $data['reason']);

        return back()->with('status', 'API key replaced. It applies to web requests immediately and to queue workers on their next job.');
    }

    public function destroySecret(Request $request): RedirectResponse
    {
        Gate::authorize('admin');

        if (AppSettings::isLocked('meta.api_key')) {
            return back()->with('error', 'The API key is managed by the MODEL_API_KEY environment variable and cannot be removed here.');
        }

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        AppSettings::removeSecret('meta.api_key');
        AdminAuditEvent::recordSecretChange('secret.removed', $request->user(), ['key' => 'meta.api_key'], $data['reason']);

        return back()->with('status', 'Stored API key removed. Model requests will fail until a key is configured.');
    }

    /**
     * Explicit connection check. Two honest modes: a local format check
     * (free, proves nothing about validity) and one minimal inference
     * request (billable, proves the key works). No non-billable provider
     * validation endpoint is known, so none is claimed.
     */
    public function testConnection(Request $request): RedirectResponse
    {
        Gate::authorize('admin');

        $data = $request->validate(['mode' => ['required', 'in:format,live']]);

        $key = AppSettings::get('meta.api_key');
        if (! is_string($key) || $key === '') {
            return back()->with('error', 'No API key is configured.');
        }

        if ($data['mode'] === 'format') {
            $looksSane = strlen($key) >= 16 && ! str_contains($key, ' ');
            AdminAuditEvent::record('secret.tested', $request->user(), null, 'Local format check (non-billable).');

            return back()->with($looksSane ? 'status' : 'error', $looksSane
                ? 'Format check passed (length/charset only — this does not prove the key is valid).'
                : 'Format check failed: the stored key looks truncated or malformed.');
        }

        try {
            // Metered as unattributed system usage (not charged to any
            // personal budget); audit-logged below.
            app(AIProviderInterface::class)->complete(new AIRequest(
                system: 'Reply with the single word: ok',
                user: 'ok',
                maxCompletionTokens: 5,
                correlationId: 'connection-test-'.now()->format('YmdHis'),
                usage: ['feature' => 'connection_test', 'operation' => 'connection-test'],
            ));

            AdminAuditEvent::record('secret.tested', $request->user(), null, 'Live minimal inference (billable).');

            return back()->with('status', 'Live test succeeded: the provider accepted the key and returned a completion. This was a billable inference request.');
        } catch (\Throwable $e) {
            AdminAuditEvent::record('secret.tested', $request->user(), null, 'Live minimal inference (billable).', null, null, 'failed');

            return back()->with('error', 'Live test failed: '.$e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $spec */
    private function validateValue(string $key, array $spec, mixed $raw): array
    {
        if (($raw === null || $raw === '') && in_array($key, ['ai.price_input_per_1k_micros', 'ai.price_output_per_1k_micros', 'ai.price_version', 'ai.daily_cost_cap_micros'], true)) {
            return ['ok' => true, 'value' => null];
        }

        if ($spec['type'] === 'bool') {
            return ['ok' => true, 'value' => filter_var($raw, FILTER_VALIDATE_BOOLEAN)];
        }

        if ($spec['type'] === 'int') {
            if (! is_numeric($raw)) {
                return ['ok' => false, 'error' => "{$key} must be a number."];
            }
            $value = (int) $raw;
            if (isset($spec['min']) && $value < $spec['min']) {
                return ['ok' => false, 'error' => "{$key} is below its minimum."];
            }
            if (isset($spec['max']) && $value > $spec['max']) {
                return ['ok' => false, 'error' => "{$key} is above its maximum."];
            }

            return ['ok' => true, 'value' => $value];
        }

        $value = substr(trim((string) $raw), 0, 500);
        if (isset($spec['options']) && ! in_array($value, $spec['options'], true)) {
            return ['ok' => false, 'error' => "{$key} must be one of: ".implode(', ', $spec['options']).'.'];
        }

        return ['ok' => true, 'value' => $value];
    }
}
