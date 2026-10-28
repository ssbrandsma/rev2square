<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Open = 'OPEN';
    case Claimed = 'CLAIMED';
    case InProgress = 'IN_PROGRESS';
    case Blocked = 'BLOCKED';
    case ReadyForVerification = 'READY_FOR_VERIFICATION';
    case Completed = 'COMPLETED';
    case VerifiedComplete = 'VERIFIED_COMPLETE';
    case Retired = 'RETIRED';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Claimed => 'Claimed',
            self::InProgress => 'In progress',
            self::Blocked => 'Blocked',
            self::ReadyForVerification => 'Ready for verification',
            self::Completed => 'Completed',
            self::VerifiedComplete => 'Verified complete',
            self::Retired => 'Retired',
        };
    }

    public function isOpenForClaim(): bool
    {
        return $this === self::Open;
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::VerifiedComplete;
    }
}
