<?php

namespace App\Enums;

enum ProjectPhase: string
{
    case IdeaDiscovery = 'IDEA_DISCOVERY';
    case TeamBuilding = 'TEAM_BUILDING';
    case ResearchFeasibility = 'RESEARCH_FEASIBILITY';
    case Development = 'DEVELOPMENT';
    case TestingRelease = 'TESTING_RELEASE';
    case Maintenance = 'MAINTENANCE';

    public function label(): string
    {
        return match ($this) {
            self::IdeaDiscovery => 'Idea discovery',
            self::TeamBuilding => 'Team building',
            self::ResearchFeasibility => 'Research & feasibility',
            self::Development => 'Development',
            self::TestingRelease => 'Testing & release',
            self::Maintenance => 'Maintenance',
        };
    }
}
