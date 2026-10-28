<?php

namespace App\Services\GitHub;

/**
 * A downloaded source archive failed validation (malformed, oversized, or
 * unsafe). Terminal for the import: re-downloading the same immutable
 * revision would produce the same artifact, so this never retries.
 */
class ArchiveInvalidException extends GitHubException {}
