<?php

namespace App\Enums;

enum DocCategory: string
{
    case Teardown = 'teardown';
    case CircuitBoards = 'circuit-boards';
    case ConnectionsPinouts = 'connections-pinouts';
    case Measurements = 'measurements';
    case RepairReassembly = 'repair-reassembly';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Teardown => 'Teardown',
            self::CircuitBoards => 'Circuit boards',
            self::ConnectionsPinouts => 'Connections & pinouts',
            self::Measurements => 'Measurements',
            self::RepairReassembly => 'Repair & reassembly',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
