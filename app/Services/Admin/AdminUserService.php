<?php

namespace App\Services\Admin;

use App\Enums\AccountStatus;
use App\Enums\GlobalRole;
use App\Models\AdminAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LastAdminException extends \RuntimeException {}

/**
 * Consequential account changes. Every mutation runs inside a
 * transaction that locks the target row plus the active-admin set, so
 * two concurrent demotions/suspensions cannot strangle the last
 * administrator. Every change writes an audit record.
 */
final class AdminUserService
{
    /**
     * @param  array{global_role?: string, ai_enabled?: bool, ai_daily_request_limit?: ?int, ai_daily_token_limit?: ?int, ai_unlimited?: bool}  $changes
     */
    public static function updateAccount(User $actor, int $userId, array $changes, ?string $reason): User
    {
        return DB::transaction(function () use ($actor, $userId, $changes, $reason): User {
            $target = User::where('id', $userId)->lockForUpdate()->firstOrFail();

            $before = self::snapshot($target);
            $fill = [];

            if (array_key_exists('global_role', $changes)) {
                $role = GlobalRole::from($changes['global_role']);
                if ($role !== $target->global_role) {
                    self::guardLastAdmin($target);
                    $fill['global_role'] = $role;
                }
            }
            foreach (['ai_enabled', 'ai_daily_request_limit', 'ai_daily_token_limit', 'ai_unlimited'] as $field) {
                if (array_key_exists($field, $changes)) {
                    $fill[$field] = $changes[$field];
                }
            }

            $target->forceFill($fill)->save();

            AdminAuditEvent::record('account.updated', $actor, $target, $reason, $before, self::snapshot($target->fresh()));

            return $target->fresh();
        });
    }

    public static function suspend(User $actor, int $userId, string $reason): User
    {
        return DB::transaction(function () use ($actor, $userId, $reason): User {
            $target = User::where('id', $userId)->lockForUpdate()->firstOrFail();

            if (! $target->isSuspended()) {
                self::guardLastAdmin($target);
                $before = self::snapshot($target);
                $target->forceFill([
                    'account_status' => AccountStatus::Suspended,
                    'suspended_at' => now(),
                ])->save();

                self::revokeSessions($target);

                AdminAuditEvent::record('account.suspended', $actor, $target, $reason, $before, self::snapshot($target->fresh()));
            }

            return $target->fresh();
        });
    }

    public static function reactivate(User $actor, int $userId, string $reason): User
    {
        return DB::transaction(function () use ($actor, $userId, $reason): User {
            $target = User::where('id', $userId)->lockForUpdate()->firstOrFail();

            if ($target->isSuspended()) {
                $before = self::snapshot($target);
                // Reactivation restores access only: role, AI limits, and
                // AI restriction stay exactly as they were.
                $target->forceFill([
                    'account_status' => AccountStatus::Active,
                    'suspended_at' => null,
                ])->save();

                AdminAuditEvent::record('account.reactivated', $actor, $target, $reason, $before, self::snapshot($target->fresh()));
            }

            return $target->fresh();
        });
    }

    /**
     * Refuse to demote or suspend the last active administrator. The
     * active-admin set is locked before counting, so a concurrent
     * request blocks here instead of slipping through.
     *
     * @throws LastAdminException
     */
    private static function guardLastAdmin(User $target): void
    {
        if (! $target->isAdmin() || $target->isSuspended()) {
            return;
        }

        $activeAdmins = User::where('global_role', GlobalRole::Admin)
            ->where('account_status', AccountStatus::Active)
            ->lockForUpdate()
            ->count();

        if ($activeAdmins <= 1) {
            throw new LastAdminException('This is the last active administrator. Promote another administrator first.');
        }
    }

    /** @return array<string, mixed> */
    private static function snapshot(User $user): array
    {
        return [
            'global_role' => $user->global_role->value,
            'account_status' => $user->account_status->value,
            'ai_enabled' => $user->ai_enabled,
            'ai_daily_request_limit' => $user->ai_daily_request_limit,
            'ai_daily_token_limit' => $user->ai_daily_token_limit,
            'ai_unlimited' => $user->ai_unlimited,
        ];
    }

    private static function revokeSessions(User $target): void
    {
        try {
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $target->id)->delete();
            }
        } catch (\Throwable) {
            // Non-database session drivers have no rows to delete; the
            // per-request status middleware still revokes access.
        }

        $target->forceFill(['remember_token' => null])->save();
    }
}
