<?php

namespace App\Services\Binary\Adapters;

/**
 * Shared safety boundary for subprocess backends: fixed executable
 * paths, argument allowlists, no shell, bounded wall time, and
 * working directories confined to one per-job temp dir.
 *
 * Network isolation, cgroups, and the non-root worker user are
 * owner-operated deployment steps (see docs/binary-analysis-setup.md);
 * this class enforces what application code can: argv safety, path
 * confinement, timeouts, and output caps.
 */
abstract class ExternalBackend
{
    /** @return list<string> exact argv (no shell) — null when inputs are rejected */
    abstract public function buildCommand(array $params): ?array;

    abstract public function binaryPath(): string;

    protected function probeVersion(string $path, array $versionArgs, string $pattern): ?string
    {
        if ($path === '' || ! is_file($path) || ! is_executable($path)) {
            return null;
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(array_merge([$path], $versionArgs), $descriptors, $pipes, sys_get_temp_dir(), ['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C']);
        if (! is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 10);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            return null;
        }

        return preg_match($pattern, $out, $m) === 1 ? $m[0] : null;
    }

    /**
     * Confine a path to the job directory. Returns the real path or
     * null — never a partially-validated string.
     */
    protected function confine(string $jobDir, string $path): ?string
    {
        $realJob = realpath($jobDir);
        $real = realpath($path) ?: ($this->realpathParent($path));
        if ($realJob === false || $real === false) {
            return null;
        }

        return str_starts_with($real, $realJob.DIRECTORY_SEPARATOR) || $real === $realJob ? $real : null;
    }

    private function realpathParent(string $path): string|false
    {
        $parent = realpath(dirname($path));
        if ($parent === false) {
            return false;
        }

        return $parent.DIRECTORY_SEPARATOR.basename($path);
    }
}
