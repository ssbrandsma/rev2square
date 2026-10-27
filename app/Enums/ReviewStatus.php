<?php

namespace App\Enums;

enum ReviewStatus: string
{
    case PendingReview = 'PENDING_REVIEW';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending human review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
