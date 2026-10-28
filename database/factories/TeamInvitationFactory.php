<?php

namespace Database\Factories;

use App\Enums\InvitationStatus;
use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TeamInvitation> */
class TeamInvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'role' => ProjectRole::Maintainer,
            'token' => Str::random(48),
            'status' => InvitationStatus::Pending,
            'expires_at' => now()->addDays(14),
        ];
    }
}
