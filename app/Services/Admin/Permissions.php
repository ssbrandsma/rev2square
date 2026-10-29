<?php

namespace App\Services\Admin;

use App\Enums\GlobalRole;
use App\Models\User;

/**
 * Small explicit global permission matrix. Global roles never imply
 * project powers (Owner/Maintainer/Contributor stay separate), and no
 * global role bypasses project privacy boundaries: admin tools show
 * aggregate metadata, never private conversations or artifact contents.
 */
final class Permissions
{
    /**
     * @return array<string, array{label: string, roles: list<GlobalRole>}>
     */
    public static function matrix(): array
    {
        return [
            'admin.access' => [
                'label' => 'Open the admin area and dashboard',
                'roles' => [GlobalRole::Admin],
            ],
            'admin.users.view' => [
                'label' => 'View the user directory and administrative metadata',
                'roles' => [GlobalRole::Admin],
            ],
            'admin.users.manage' => [
                'label' => 'Change roles, suspend/reactivate, set AI limits',
                'roles' => [GlobalRole::Admin],
            ],
            'admin.settings.view' => [
                'label' => 'View application settings and key status metadata',
                'roles' => [GlobalRole::Admin],
            ],
            'admin.settings.manage' => [
                'label' => 'Change ordinary settings (never secrets)',
                'roles' => [GlobalRole::Admin],
            ],
            'admin.secrets.manage' => [
                'label' => 'Replace or remove the Meta API key (write-only)',
                'roles' => [GlobalRole::Admin],
            ],
            'admin.usage.view' => [
                'label' => 'View aggregate and per-user AI usage metadata',
                'roles' => [GlobalRole::Admin],
            ],
            'admin.audit.view' => [
                'label' => 'View the administrative audit log',
                'roles' => [GlobalRole::Admin],
            ],
            'moderate.reports' => [
                'label' => 'Review community reports (dismiss / remove with reason)',
                'roles' => [GlobalRole::Moderator, GlobalRole::Admin],
            ],
        ];
    }

    public static function allows(?User $user, string $ability): bool
    {
        if ($user === null || $user->isSuspended()) {
            return false;
        }

        $entry = self::matrix()[$ability] ?? null;

        return $entry !== null && in_array($user->global_role, $entry['roles'], true);
    }
}
