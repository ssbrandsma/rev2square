<?php

namespace App\Console\Commands;

use App\Models\BinaryAnalysis;
use App\Models\ProjectArtifact;
use App\Services\Artifacts\ArtifactExtractor;
use App\Services\Artifacts\ArtifactStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only storage audit: every artifact row's raw bytes must exist
 * with matching size and SHA-256, usable rows must still have their
 * extraction snapshot, and usable analyses must still have evidence.
 *
 * Used before/after storage migrations and restores. Reports problems
 * and exits 1; it never modifies rows or files. Recovery is always
 * restore-files-first, then retry from the artifact/analysis pages —
 * failed rows are never auto-deleted.
 */
class VerifyArtifactStorageCommand extends Command
{
    protected $signature = 'artifacts:verify-storage';

    protected $description = 'Verify stored artifact files against database records (read-only).';

    public function handle(): int
    {
        $disk = Storage::disk('artifacts');
        $problems = [];
        $checked = 0;

        ProjectArtifact::query()->orderBy('id')->chunk(200, function ($artifacts) use ($disk, &$problems, &$checked) {
            foreach ($artifacts as $artifact) {
                $checked++;
                $status = ArtifactStorage::status('artifacts', $artifact->rawPath());
                if ($status === ArtifactStorage::OK) {
                    if ($disk->size($artifact->rawPath()) !== (int) $artifact->byte_size
                        || ArtifactExtractor::streamSha256($disk, $artifact->rawPath()) !== $artifact->sha256) {
                        $problems[] = [$artifact->id, $artifact->state, 'raw bytes fail size/SHA-256 check'];
                    } elseif ($artifact->state === ProjectArtifact::STATE_FAILED
                        && in_array($artifact->failure_code, ['missing_input', 'storage_unreadable'], true)) {
                        $problems[] = [$artifact->id, $artifact->state, 'file is present again — retry from the artifact page'];
                    }
                } else {
                    $problems[] = [$artifact->id, $artifact->state, $status === ArtifactStorage::MISSING ? 'raw file missing' : 'raw file unreadable (permissions)'];
                }

                if ($artifact->isUsable()
                    && ArtifactStorage::status('artifacts', $artifact->snapshotPath()) !== ArtifactStorage::OK) {
                    $problems[] = [$artifact->id, $artifact->state, 'extraction snapshot missing/unreadable'];
                }
            }
        });

        $analyses = 0;
        BinaryAnalysis::query()
            ->whereIn('status', [BinaryAnalysis::STATUS_READY, BinaryAnalysis::STATUS_PARTIAL])
            ->with('artifact')
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$problems, &$analyses) {
                foreach ($rows as $analysis) {
                    $analyses++;
                    $path = $analysis->artifact === null ? null : $analysis->evidencePath();
                    if ($path === null || ArtifactStorage::status('artifacts', $path) !== ArtifactStorage::OK) {
                        $problems[] = ['analysis '.$analysis->id, $analysis->status, 'evidence missing/unreadable'];
                    }
                }
            });

        if ($problems !== []) {
            $this->table(['Row', 'State', 'Problem'], $problems);
        }
        $this->info("Checked {$checked} artifact(s) and {$analyses} usable analys(es): ".count($problems).' problem(s).');

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
