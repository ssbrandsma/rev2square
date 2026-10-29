<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('global_role', 16)->default('user')->after('remember_token');
            $table->string('account_status', 16)->default('active')->after('global_role');
            $table->timestamp('suspended_at')->nullable()->after('account_status');
            $table->boolean('ai_enabled')->default(true)->after('suspended_at');
            $table->unsignedInteger('ai_daily_request_limit')->nullable()->after('ai_enabled');
            $table->unsignedInteger('ai_daily_token_limit')->nullable()->after('ai_daily_request_limit');
            $table->boolean('ai_unlimited')->default(false)->after('ai_daily_token_limit');

            $table->index('global_role');
            $table->index('account_status');
        });

        // Ordinary settings (typed allowlist enforced in code) plus
        // write-only secrets (encrypted, never rendered back).
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Auditable per-attempt AI usage ledger. One row per provider
        // attempt; idempotency_key keeps job retries from duplicating.
        Schema::create('ai_usage_entries', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 96)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('feature', 32);
            $table->string('operation', 96);
            $table->unsignedTinyInteger('attempt_seq')->default(1);
            $table->string('provider', 32);
            $table->string('model', 64)->nullable();
            $table->string('status', 16);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cached_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('correlation_id', 96)->nullable();
            $table->unsignedBigInteger('estimated_cost_micros')->nullable();
            $table->string('pricing_version', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['feature', 'created_at']);
            $table->index('correlation_id');
        });

        // Per-user-day budget counters. Rows are locked (SELECT ... FOR
        // UPDATE) during reservation so concurrent requests cannot
        // overspend the same allowance.
        Schema::create('ai_budget_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('tokens')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'day']);
        });

        // Administrative audit trail. Append-only by convention: no edit
        // or delete path exists in the application. Secrets are never
        // stored here (see AdminAudit::recordSecretChange).
        Schema::create('admin_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('outcome', 16)->default('success');
            $table->nullableMorphs('target');
            $table->text('reason')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('correlation_id', 96)->nullable();
            $table->timestamps();

            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_events');
        Schema::dropIfExists('ai_budget_counters');
        Schema::dropIfExists('ai_usage_entries');
        Schema::dropIfExists('app_settings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['global_role']);
            $table->dropIndex(['account_status']);
            $table->dropColumn([
                'global_role', 'account_status', 'suspended_at', 'ai_enabled',
                'ai_daily_request_limit', 'ai_daily_token_limit', 'ai_unlimited',
            ]);
        });
    }
};
