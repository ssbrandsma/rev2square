<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeBinaryJob;
use App\Models\BinaryAnalysis;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Services\Binary\Adapters\SandboxedBackend;
use App\Services\Binary\AnalysisRequest;
use App\Services\Binary\OutputArchive;
use App\Services\Binary\Targets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BinaryAnalysisController extends Controller
{
    public function show(Project $project, ProjectArtifact $artifact, BinaryAnalysis $analysis): View
    {
        Gate::authorize('view', $project);
        $this->authorizeAnalysis($project, $artifact, $analysis);

        return view('projects.artifacts.analysis-show', [
            'project' => $project,
            'artifact' => $artifact,
            'analysis' => $analysis,
            'evidence' => $analysis->isUsable() ? $analysis->readEvidence() : null,
            'snapshot' => $artifact->readSnapshot(),
            'cpuTargets' => self::cpuTargets(),
        ]);
    }

    public function store(Request $request, Project $project, ProjectArtifact $artifact): RedirectResponse
    {
        Gate::authorize('view', $project);
        $this->authorizeArtifact($project, $artifact);

        $data = $request->validate([
            'target' => 'nullable|string|max:64',
            'base_address' => 'nullable|string|max:32',
            'entry' => 'nullable|string|max:32',
            'platform' => 'nullable|string|max:32',
            'adapter' => 'nullable|string|max:32',
            'member' => 'nullable|string|max:200',
        ]);

        $result = AnalysisRequest::request($artifact, $request->user(), $data);
        if (! ($result['ok'] ?? false)) {
            return back()->withInput()->with('error', $result['error'] ?? 'Analysis could not be queued.');
        }

        $analysis = $result['analysis'];
        if (! empty($result['reused'])) {
            $message = match ($analysis->status) {
                BinaryAnalysis::STATUS_QUEUED => 'Identical analysis already queued — no duplicate job was scheduled.',
                BinaryAnalysis::STATUS_ANALYZING => 'Identical analysis already running — no duplicate job was scheduled.',
                BinaryAnalysis::STATUS_READY, BinaryAnalysis::STATUS_PARTIAL => 'Identical analysis already exists — showing the existing run.',
                BinaryAnalysis::STATUS_CANCELLED => 'The identical run was cancelled. Retry it from the analysis page if still needed.',
                default => 'The identical run failed ('.($analysis->error_message ?? 'unknown error').'). Retry it from the analysis page.',
            };

            return redirect()->route('projects.artifacts.analyses.show', [$project, $artifact, $analysis])
                ->with($analysis->isUsable() || ! $analysis->isTerminal() ? 'status' : 'error', $message);
        }

        return redirect()->route('projects.artifacts.analyses.show', [$project, $artifact, $analysis])
            ->with('status', 'Analysis queued ('.$analysis->adapter.', target '.$analysis->target.').');
    }

    public function cancel(Project $project, ProjectArtifact $artifact, BinaryAnalysis $analysis): RedirectResponse
    {
        Gate::authorize('view', $project);
        $this->authorizeAnalysis($project, $artifact, $analysis);

        if ($analysis->isTerminal()) {
            return back()->with('error', 'This run is already '.$analysis->statusLabel().'.');
        }

        $analysis->forceFill([
            'status' => BinaryAnalysis::STATUS_CANCELLED,
            'progress_note' => null,
            'finished_at' => now(),
        ])->save();

        return back()->with('status', 'Analysis run cancelled. A queued job for it will exit without working.');
    }

    public function retry(Project $project, ProjectArtifact $artifact, BinaryAnalysis $analysis): RedirectResponse
    {
        Gate::authorize('view', $project);
        $this->authorizeAnalysis($project, $artifact, $analysis);

        if (! $analysis->isRetryable()) {
            $reason = $analysis->status === BinaryAnalysis::STATUS_FAILED
                ? 'This failure ('.($analysis->error_code ?? 'unknown').') is not recoverable by retry. Adjust the target configuration and queue a fresh run.'
                : 'Only failed runs can be retried. This run is '.$analysis->statusLabel().'.';

            return back()->with('error', $reason);
        }

        $claimed = BinaryAnalysis::where('id', $analysis->id)
            ->where('status', BinaryAnalysis::STATUS_FAILED)
            ->update([
                'status' => BinaryAnalysis::STATUS_QUEUED,
                'progress_note' => null,
                'error_code' => null,
                'error_message' => null,
                'started_at' => null,
                'finished_at' => null,
            ]);

        if ($claimed === 0) {
            return back()->with('status', 'This run is no longer failed — no duplicate job was scheduled.');
        }

        AnalyzeBinaryJob::dispatch($analysis->id);

        return back()->with('status', 'Analysis queued again.');
    }

    /**
     * Download the run's retained tool-output archive. Same project
     * visibility as the run itself; served as an inert attachment,
     * never inline — the contents are untrusted reconstruction.
     */
    public function download(Project $project, ProjectArtifact $artifact, BinaryAnalysis $analysis): StreamedResponse|RedirectResponse
    {
        Gate::authorize('view', $project);
        $this->authorizeAnalysis($project, $artifact, $analysis);

        if (! $analysis->isUsable() || ! $analysis->hasOutputArchive()) {
            return redirect()->route('projects.artifacts.analyses.show', [$project, $artifact, $analysis])
                ->with('error', 'No downloadable output is retained for this run. Queue a regeneration to produce one.');
        }

        return Storage::disk('artifacts')->download(
            $analysis->outputPath(),
            'analysis-'.$analysis->id.'-reconstructed-sources.zip',
            ['Content-Type' => 'application/zip', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    /**
     * Paginated source browser over the retained archive (preferred)
     * or the run's bounded evidence manifest (legacy runs). Query:
     * kind=all|source|resource, q=name filter, path=file to view,
     * page. Content renders escaped with line numbers; larger files
     * stay download-only.
     */
    public function source(Request $request, Project $project, ProjectArtifact $artifact, BinaryAnalysis $analysis): View|RedirectResponse
    {
        Gate::authorize('view', $project);
        $this->authorizeAnalysis($project, $artifact, $analysis);

        if (! $analysis->isUsable()) {
            return redirect()->route('projects.artifacts.analyses.show', [$project, $artifact, $analysis])
                ->with('error', 'This run is '.$analysis->statusLabel().' — there are no sources to browse yet.');
        }

        $kind = $request->query('kind', 'all');
        $kind = in_array($kind, ['all', 'source', 'resource'], true) ? $kind : 'all';
        $query = is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 100) : '';
        $path = is_string($request->query('path')) && $request->query('path') !== '' ? $request->query('path') : null;
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(10, (int) config('artifacts.binary_source_browser_page', 100));

        $disk = Storage::disk('artifacts');
        $entries = null;
        $listingSource = 'archive';
        if ($analysis->hasOutputArchive()) {
            $listed = OutputArchive::listEntries($disk->path($analysis->outputPath()));
            if ($listed !== null) {
                $entries = $listed['entries'];
            }
        }
        if ($entries === null) {
            // Legacy runs (pre-archive runners) and unreadable
            // archives fall back to the evidence manifest; only
            // inline files carry viewable content there.
            $listingSource = 'evidence';
            $entries = self::evidenceEntries($analysis->readEvidence());
        }
        if ($query !== '') {
            $entries = array_values(array_filter($entries, fn ($e) => mb_stripos($e['name'], $query) !== false));
        }
        if ($kind !== 'all') {
            $entries = array_values(array_filter($entries, fn ($e) => $e['kind'] === $kind));
        }

        $total = count($entries);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $pageEntries = array_slice($entries, ($page - 1) * $perPage, $perPage);

        $viewed = null;
        if ($path !== null) {
            $viewed = $this->viewFile($analysis, $listingSource, $path);
            if ($viewed === null) {
                return redirect()->route('projects.artifacts.analyses.source', [$project, $artifact, $analysis])
                    ->with('error', 'That file is not part of this run’s retained output.');
            }
        }

        return view('projects.artifacts.analysis-source', [
            'project' => $project,
            'artifact' => $artifact,
            'analysis' => $analysis,
            'listingSource' => $listingSource,
            'kind' => $kind,
            'query' => $query,
            'entries' => $pageEntries,
            'total' => $total,
            'page' => $page,
            'lastPage' => $lastPage,
            'viewed' => $viewed,
        ]);
    }

    /**
     * Queue a fresh retention run for a usable run whose output was
     * not retained (pre-archive runner, discarded archive, pruned
     * storage). This REPLACES the current results: one row holds one
     * consistent result set, so evidence and archive are never mixed
     * across runner versions. Forbidden when output already exists.
     */
    public function regenerate(Project $project, ProjectArtifact $artifact, BinaryAnalysis $analysis): RedirectResponse
    {
        Gate::authorize('view', $project);
        $this->authorizeAnalysis($project, $artifact, $analysis);

        if (! $analysis->isUsable()) {
            return back()->with('error', 'Only finished runs can be regenerated. This run is '.$analysis->statusLabel().'.');
        }
        if ($analysis->hasOutputArchive()) {
            return back()->with('error', 'This run already retains downloadable output — nothing to regenerate.');
        }

        $claimed = BinaryAnalysis::where('id', $analysis->id)
            ->whereIn('status', [BinaryAnalysis::STATUS_READY, BinaryAnalysis::STATUS_PARTIAL])
            ->update([
                'status' => BinaryAnalysis::STATUS_QUEUED,
                'progress_note' => null,
                'error_code' => null,
                'error_message' => null,
                'evidence_key' => null,
                'output_meta' => null,
                'started_at' => null,
                'finished_at' => null,
            ]);

        if ($claimed === 0) {
            return back()->with('status', 'This run changed state — no duplicate job was scheduled.');
        }

        AnalyzeBinaryJob::dispatch($analysis->id);

        return redirect()->route('projects.artifacts.analyses.show', [$project, $artifact, $analysis])
            ->with('status', 'Regeneration queued. The current results will be replaced by a fresh run that retains downloadable output.');
    }

    /**
     * @return array{name: string, size: int, lines: list<string>|null, lineCount: int|null, lineTruncated: bool, notice: string|null}|null
     */
    private function viewFile(BinaryAnalysis $analysis, string $listingSource, string $path): ?array
    {
        if (! SandboxedBackend::safeRelativePath($path) || strlen($path) > 255) {
            return null;
        }
        $maxBytes = max(1024, (int) config('artifacts.binary_source_browser_max_bytes', 256 * 1024));

        if ($listingSource === 'archive') {
            $known = OutputArchive::listEntries(Storage::disk('artifacts')->path($analysis->outputPath()));
            $size = null;
            if ($known !== null) {
                foreach ($known['entries'] as $entry) {
                    if ($entry['name'] === $path) {
                        $size = $entry['size'];

                        break;
                    }
                }
            }
            if ($size === null) {
                return null;
            }
            if ($size > $maxBytes) {
                return ['name' => $path, 'size' => $size, 'lines' => null, 'lineCount' => null, 'lineTruncated' => false, 'notice' => 'This file ('.number_format($size).' bytes) exceeds the inline display cap ('.number_format($maxBytes).' bytes). Download the archive to read it.'];
            }
            $bytes = OutputArchive::readEntry(Storage::disk('artifacts')->path($analysis->outputPath()), $path, $maxBytes);
            if ($bytes === null) {
                return ['name' => $path, 'size' => $size, 'lines' => null, 'lineCount' => null, 'lineTruncated' => false, 'notice' => 'This file could not be read from the archive. Download the archive to inspect it directly.'];
            }
            if (str_contains($bytes, "\0") || ! mb_check_encoding($bytes, 'UTF-8')) {
                return ['name' => $path, 'size' => $size, 'lines' => null, 'lineCount' => null, 'lineTruncated' => false, 'notice' => 'Binary file — not displayed inline. Download the archive to inspect it.'];
            }

            return self::toLines($path, $size, $bytes);
        }

        $evidence = $analysis->readEvidence();
        foreach (self::evidenceEntries($evidence) as $entry) {
            if ($entry['name'] === $path) {
                $size = $entry['size'];
                $content = self::evidenceInline($evidence, $path);
                if ($content === null) {
                    return ['name' => $path, 'size' => $size, 'lines' => null, 'lineCount' => null, 'lineTruncated' => false, 'notice' => 'This run predates retained output: only files with inline evidence content can be viewed. Regenerate the run for full sources.'];
                }

                return self::toLines($path, $size, $content);
            }
        }

        return null;
    }

    /** @return array{name: string, size: int, lines: list<string>, lineCount: int, lineTruncated: bool, notice: string|null} */
    private static function toLines(string $path, int $size, string $content): array
    {
        $lines = preg_split("/\r\n|\r|\n/", $content) ?: [];
        $lineCount = count($lines);
        $cap = 5000;
        $truncated = $lineCount > $cap;
        if ($truncated) {
            $lines = array_slice($lines, 0, $cap);
        }

        return ['name' => $path, 'size' => $size, 'lines' => array_values($lines), 'lineCount' => $lineCount, 'lineTruncated' => $truncated, 'notice' => null];
    }

    /** @return list<array{name: string, size: int, kind: string}> */
    private static function evidenceEntries(?array $evidence): array
    {
        $files = $evidence['reconstruction']['files'] ?? $evidence['extraction']['files'] ?? [];
        $entries = [];
        if (is_array($files)) {
            foreach ($files as $file) {
                if (! is_array($file) || ! is_string($file['path'] ?? null)) {
                    continue;
                }
                $entries[] = ['name' => $file['path'], 'size' => (int) ($file['size'] ?? 0), 'kind' => OutputArchive::kindOf($file['path'])];
            }
        }
        usort($entries, fn ($a, $b) => [$a['kind'] === 'source' ? 0 : 1, $a['name']] <=> [$b['kind'] === 'source' ? 0 : 1, $b['name']]);

        return $entries;
    }

    private static function evidenceInline(?array $evidence, string $path): ?string
    {
        $inline = $evidence['reconstruction']['inline'] ?? $evidence['extraction']['inline'] ?? null;

        return is_array($inline) && is_string($inline[$path] ?? null) ? $inline[$path] : null;
    }

    /** @return list<array{id: string, name: string}> CPU targets for native-library child runs. */
    private static function cpuTargets(): array
    {
        $out = [];
        foreach (Targets::all() as $id => $info) {
            if (in_array($id, ['dalvik', 'jvm', 'clr', 'extract'], true)) {
                continue;
            }
            $out[] = ['id' => $id, 'name' => $info['name']];
        }

        return $out;
    }

    private function authorizeArtifact(Project $project, ProjectArtifact $artifact): void
    {
        if ($artifact->project_id !== $project->id) {
            abort(404);
        }
    }

    private function authorizeAnalysis(Project $project, ProjectArtifact $artifact, BinaryAnalysis $analysis): void
    {
        $this->authorizeArtifact($project, $artifact);
        if ($analysis->project_artifact_id !== $artifact->id) {
            abort(404);
        }
    }
}
