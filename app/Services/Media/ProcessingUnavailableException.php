<?php

namespace App\Services\Media;

/**
 * Environmental image-processing failure (missing GD on the worker,
 * transient storage/queue fault). The image itself may be fine, so
 * the worker retries with backoff and the maintainer may retry
 * manually once the environment is repaired.
 */
class ProcessingUnavailableException extends \RuntimeException {}
