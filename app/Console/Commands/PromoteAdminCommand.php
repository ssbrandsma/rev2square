<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\GlobalRole;
use App\Models\AdminAuditEvent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Owner-operated first-admin bootstrap. There is no public endpoint,
 * no default admin, and no reusable token: the owner runs this from a
 * shell against an existing account and confirms explicitly.
 */
class PromoteAdminCommand extends Command
{
    protected $signature = 'admin:promote
        {identifier : Email address or @handle of the existing account}
        {--by-handle : Treat the identifier as a public handle instead of an email}';

    protected $description = 'Promote an existing account to global administrator (owner-operated)';

    public function handle(): int
    {
        $identifier = (string) $this->argument('identifier');

        $user = $this->option('by-handle')
            ? User::where('handle', ltrim($identifier, '@'))->first()
            : User::where('email', $identifier)->first();

        if ($user === null) {
            $this->error('No account found. Create the account through normal registration first.');

            return self::FAILURE;
        }

        if ($user->isAdmin() && ! $user->isSuspended()) {
            $this->info("{$user->email} is already an active administrator. Nothing to do.");

            return self::SUCCESS;
        }

        $this->warn("About to grant GLOBAL ADMINISTRATOR to: {$user->name} <{$user->email}> (id {$user->id}).");
        $this->warn('Administrators manage users, roles, settings, secrets metadata, usage, and audit records.');

        if (! $this->confirm('Type yes to confirm this promotion.', false)) {
            $this->info('Aborted. No changes made.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user): void {
            $before = ['global_role' => $user->global_role->value, 'account_status' => $user->account_status->value];
            $user->forceFill([
                'global_role' => GlobalRole::Admin,
                'account_status' => AccountStatus::Active,
                'suspended_at' => null,
            ])->save();

            AdminAuditEvent::record(
                'role.promoted',
                null,
                $user,
                'Owner-operated console bootstrap (admin:promote).',
                $before,
                ['global_role' => 'admin', 'account_status' => 'active']
            );
        });

        $this->info("{$user->email} is now a global administrator.");

        return self::SUCCESS;
    }
}
