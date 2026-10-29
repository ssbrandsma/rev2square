<?php

namespace App\Services\Binary\Adapters;

/** Classified adapter failure. The code drives retry policy; the message is user-facing and safe. */
class AnalysisFailedException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
