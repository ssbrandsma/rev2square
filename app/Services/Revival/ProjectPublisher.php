<?php

namespace App\Services\Revival;

use App\Enums\MilestoneStatus;
use App\Enums\ReviewStatus;
use App\Enums\TaskStatus;
use App\Models\AnalysisReport;
use App\Models\ContributorTask;
use App\Models\Milestone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Converts an approved report's roadmap into normalized milestones and
 * contributor tasks. Transactional and idempotent:
 *
 * - Identity keys derive from the per-project stable title slug, never the
 *   report version, so re-publishing matches existing rows and preserves
 *   IDs, assignments, and contribution history.
 * - Rows without an import key (seeded/manual) are never touched.
 * - Manually edited rows keep their content; only provenance is refreshed.
 * - Row statuses are never reset by publishing.
 */
class ProjectPublisher
{
    /**
     * @return array{milestones_created: int, milestones_updated: int, tasks_created: int, tasks_updated: int}
     *
     * @throws PublisherException
     */
    public function publish(AnalysisReport $report, User $actor): array
    {
        if ($report->review_status !== ReviewStatus::Approved) {
            throw new PublisherException('Only an approved report can be published.');
        }

        $payload = $report->payload_json;
        $milestones = $payload['milestones'] ?? null;
        $tasks = $payload['contributor_tasks'] ?? null;

        if (! is_array($milestones) || ! is_array($tasks)) {
            throw new PublisherException('Report payload has no publishable roadmap.');
        }

        foreach ($milestones as $i => $item) {
            if (! is_string($item['title'] ?? null) || trim($item['title']) === '') {
                throw new PublisherException("Milestone #{$i} has a blank title.");
            }
        }

        foreach ($tasks as $i => $item) {
            if (! is_string($item['title'] ?? null) || trim($item['title']) === '') {
                throw new PublisherException("Task #{$i} has a blank title.");
            }
        }

        return DB::transaction(function () use ($report, $milestones, $tasks) {
            $this->taskMilestones = [];
            $milestoneRows = [];
            $created = ['milestones_created' => 0, 'milestones_updated' => 0, 'tasks_created' => 0, 'tasks_updated' => 0];

            foreach ($milestones as $order => $item) {
                $key = $this->milestoneKey($item['title']);

                $row = Milestone::firstOrNew([
                    'project_id' => $report->project_id,
                    'import_key' => $key,
                ]);

                if (! $row->exists) {
                    $row->fill([
                        'title' => $item['title'],
                        'description' => $item['description'] ?? null,
                        'status' => MilestoneStatus::Planned,
                        'sort_order' => $order,
                        'origin_report_id' => $report->id,
                    ]);
                    $created['milestones_created']++;
                } elseif (! $row->manually_edited) {
                    $row->fill([
                        'title' => $item['title'],
                        'description' => $item['description'] ?? null,
                    ]);
                    $created['milestones_updated']++;
                } else {
                    $created['milestones_updated']++;
                }

                $row->last_seen_report_id = $report->id;
                $row->save();

                $milestoneRows[] = $row;
            }

            // Task→milestone links, resolved after all milestones exist.
            foreach ($milestones as $item) {
                $key = $this->milestoneKey($item['title']);
                $milestoneId = collect($milestoneRows)->firstWhere('import_key', $key)?->id;

                foreach ((array) ($item['task_ids'] ?? []) as $taskRef) {
                    if ($milestoneId !== null) {
                        $this->taskMilestones[$taskRef] = $milestoneId;
                    }
                }
            }

            foreach ($tasks as $item) {
                $key = $this->taskKey($item['title']);

                $row = ContributorTask::firstOrNew([
                    'project_id' => $report->project_id,
                    'import_key' => $key,
                ]);

                if (! $row->exists) {
                    $row->fill([
                        'title' => $item['title'],
                        'description' => null,
                        'skills' => $item['skill_tags'] ?? [],
                        'status' => TaskStatus::Open,
                        'definition_of_done' => $item['definition_of_done'] ?? null,
                        'requires_hardware' => (bool) ($item['requires_hardware'] ?? false),
                        'origin_report_id' => $report->id,
                    ]);
                    $created['tasks_created']++;
                } elseif (! $row->manually_edited) {
                    $row->fill([
                        'title' => $item['title'],
                        'skills' => $item['skill_tags'] ?? [],
                        'definition_of_done' => $item['definition_of_done'] ?? null,
                        'requires_hardware' => (bool) ($item['requires_hardware'] ?? false),
                    ]);
                    $created['tasks_updated']++;
                } else {
                    $created['tasks_updated']++;
                }

                $row->milestone_id = $this->taskMilestones[$item['id'] ?? ''] ?? $row->milestone_id;
                $row->last_seen_report_id = $report->id;
                $row->save();
            }

            if ($report->published_at === null) {
                $report->forceFill(['published_at' => now()])->save();
            }

            return $created;
        });
    }

    /** @var array<string, int> */
    private array $taskMilestones = [];

    private function milestoneKey(string $title): string
    {
        return 'ms:'.Str::slug(substr($title, 0, 200));
    }

    private function taskKey(string $title): string
    {
        return 'task:'.Str::slug(substr($title, 0, 200));
    }
}
