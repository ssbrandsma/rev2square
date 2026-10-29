<?php

namespace App\Services\Media;

use App\Models\ProjectMedia;

/**
 * Permanent image failure: the stored bytes cannot be decoded into
 * an image no matter how often processing is retried. Retrying is
 * refused; the maintainer must re-upload a valid file.
 */
class UnprocessableImageException extends \RuntimeException
{
    public function __construct(
        string $message = 'Stored image is unreadable.',
        public readonly string $errorCode = ProjectMedia::ERROR_INVALID_IMAGE,
    ) {
        parent::__construct($message);
    }
}
