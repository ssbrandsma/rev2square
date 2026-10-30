<?php

namespace App\Services\Ops;

/**
 * Deployment-specific owner instructions for the isolated-analysis
 * stack. Bare `docker compose ...` / `php artisan ...` hints are
 * wrong on every production host (compose file + env file + service
 * names differ per deployment), so every hint renders the exact
 * command for the current DEPLOY_VARIANT — or an honest doc pointer
 * when the variant is unknown.
 */
final class DeployHints
{
    public static function variant(): ?string
    {
        $variant = (string) env('DEPLOY_VARIANT', '');

        return in_array($variant, ['virtualmin', 'prod'], true) ? $variant : null;
    }

    public static function composeBase(): ?string
    {
        return match (self::variant()) {
            'virtualmin' => 'docker compose -f compose.virtualmin.yaml --env-file /etc/rev2square/.env.virtualmin',
            'prod' => 'docker compose -f compose.prod.yaml --env-file .env.production',
            default => null,
        };
    }

    /**
     * Full sentence telling the owner how to start the analysis stack.
     */
    public static function startRunnersHint(): string
    {
        $base = self::composeBase();
        if ($base !== null && self::variant() === 'virtualmin') {
            return 'Start the isolated runners: '.$base.' up -d analysis-coordinator objdump-runner ghidra-runner jadx-runner ilspy-runner extract-runner.';
        }
        if ($base !== null) {
            return 'compose.prod.yaml ships no analysis runners; provision the isolated stack per docs/binary-analysis-setup.md, or deploy the virtualmin variant.';
        }

        return 'Start the isolated runners for your deployment (docs/binary-analysis-setup.md §2–3).';
    }

    /**
     * Full sentence telling the owner how to refresh capability snapshots.
     */
    public static function refreshSnapshotsHint(): string
    {
        $base = self::composeBase();
        if ($base !== null && self::variant() === 'virtualmin') {
            return 'Refresh from the coordinator: '.$base.' exec -T analysis-coordinator php artisan binary:worker-capabilities --refresh (then wait a minute and re-check).';
        }
        if ($base !== null) {
            return 'compose.prod.yaml ships no analysis coordinator; provision the isolated stack per docs/binary-analysis-setup.md before queueing analysis.';
        }

        return 'Refresh with php artisan binary:worker-capabilities --refresh from any container with database access (docs/binary-analysis-setup.md §2–3).';
    }

    /**
     * Full sentence telling the owner how to rebuild one runner image.
     */
    public static function rebuildRunnerHint(string $service): string
    {
        $base = self::composeBase();
        if ($base !== null && self::variant() === 'virtualmin') {
            return 'Rebuild it: '.$base.' build '.$service.' && '.$base.' up -d '.$service.' (then refresh snapshots).';
        }
        if ($base !== null) {
            return 'compose.prod.yaml ships no analysis runners; provision the isolated stack per docs/binary-analysis-setup.md.';
        }

        return 'Rebuild the '.$service.' image for your deployment (docs/binary-analysis-setup.md §2), then refresh snapshots.';
    }

    /**
     * Full owner command running an artisan command on the coordinator,
     * or a doc pointer when the deployment is unknown.
     */
    public static function coordinatorArtisan(string $artisan): string
    {
        $base = self::composeBase();
        if ($base !== null && self::variant() === 'virtualmin') {
            return $base.' exec -T analysis-coordinator php artisan '.$artisan;
        }
        if ($base !== null) {
            return 'php artisan '.$artisan.' (needs an analysis coordinator; compose.prod.yaml ships none — see docs/binary-analysis-setup.md)';
        }

        return 'php artisan '.$artisan.' on the analysis coordinator (docs/binary-analysis-setup.md §2–3)';
    }
}
