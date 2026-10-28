<?php

namespace App\Enums;

enum AnalysisStatus: string
{
    case Draft = 'DRAFT';
    case Queued = 'QUEUED';
    case Ingesting = 'INGESTING';
    case Analyzing = 'ANALYZING';
    case ReadyForReview = 'READY_FOR_REVIEW';
    case Ready = 'READY';
    case Published = 'PUBLISHED';
    case FailedIngest = 'FAILED_INGEST';
    case FailedModel = 'FAILED_MODEL';
    case FailedValidation = 'FAILED_VALIDATION';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Queued => 'Queued',
            self::Ingesting => 'Ingesting',
            self::Analyzing => 'Analyzing',
            self::ReadyForReview => 'Ready for review',
            self::Ready => 'Ready',
            self::Published => 'Published',
            self::FailedIngest => 'Ingest failed',
            self::FailedModel => 'Model call failed',
            self::FailedValidation => 'Validation failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Ingesting, self::Analyzing], true);
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::FailedIngest, self::FailedModel, self::FailedValidation], true);
    }
}
