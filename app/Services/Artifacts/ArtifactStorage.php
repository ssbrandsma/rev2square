<?php

namespace App\Services\Artifacts;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Private-disk file-status probe for stored research artifacts.
 *
 * A worker that cannot see a stored file must not claim it was
 * deleted: a missing shared mount or denied permissions produce the
 * same empty read as physical deletion. This probe distinguishes the
 * cases internally (operator logs + failure codes) while public
 * messages stay generic and retry-oriented, never leaking paths.
 */
final class ArtifactStorage
{
    public const OK = 'ok';

    public const MISSING = 'missing';

    public const UNREADABLE = 'unreadable';

    /**
     * Probe one path on a local disk: ok, genuinely missing, or present
     * but unreadable (denied file or untraversable ancestor, e.g. an
     * unmounted shared volume shadowing the path).
     */
    public static function status(string $disk, string $path): string
    {
        $adapter = Storage::disk($disk);
        $exists = $adapter->exists($path);

        // Local-driver absolute path; both private disks are local.
        $absolute = $adapter->path($path);
        clearstatcache(true, $absolute);
        $readable = is_readable($absolute);

        if ($exists && $readable) {
            return self::OK;
        }

        if ($exists) {
            return self::UNREADABLE;
        }

        return self::ancestorsReadable($absolute, $adapter->path('')) ? self::MISSING : self::UNREADABLE;
    }

    /**
     * Pure decision table over probe inputs, so the branching is
     * unit-testable without filesystem fixtures.
     */
    public static function classify(bool $exists, bool $readable, bool $ancestorsReadable): string
    {
        if ($exists && $readable) {
            return self::OK;
        }

        if ($exists) {
            return self::UNREADABLE;
        }

        return $ancestorsReadable ? self::MISSING : self::UNREADABLE;
    }

    /**
     * Safe public message for an unavailable stored file. Retry-first:
     * re-upload is the last resort, never the only recovery.
     */
    public static function unavailableMessage(): string
    {
        return 'The stored file is unavailable right now. Retry from this page — it may have been restored — and re-upload only if retry keeps failing.';
    }

    /**
     * Internal record of an unavailable file. The absolute path stays in
     * operator logs; callers show unavailableMessage() publicly.
     */
    public static function logUnavailable(string $disk, string $path, string $status, array $context = []): void
    {
        Log::warning('Stored artifact file unavailable.', array_merge([
            'disk' => $disk,
            'path' => $path,
            'status' => $status,
        ], $context));
    }

    /**
     * Every ancestor from the file's parent up to (and including) the
     * disk root must exist and be traversable + readable; otherwise a
     * stat failure cannot be told apart from genuine absence.
     */
    private static function ancestorsReadable(string $absolute, string $root): bool
    {
        $root = rtrim($root, '/');
        $dir = dirname($absolute);

        while (true) {
            clearstatcache(true, $dir);
            if (! is_dir($dir) || ! is_readable($dir) || ! is_executable($dir)) {
                return false;
            }
            if ($dir === $root || strlen($dir) < strlen($root)) {
                return true;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                return false;
            }
            $dir = $parent;
        }
    }
}
