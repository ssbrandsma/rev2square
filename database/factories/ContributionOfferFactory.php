<?php

namespace Database\Factories;

use App\Enums\OfferStatus;
use App\Enums\OfferType;
use App\Models\ContributionOffer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContributionOffer> */
class ContributionOfferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'contributor_task_id' => null,
            'user_id' => User::factory(),
            'type' => OfferType::OfferHelp,
            'message' => fake()->sentence(),
            'status' => OfferStatus::Pending,
        ];
    }
}
