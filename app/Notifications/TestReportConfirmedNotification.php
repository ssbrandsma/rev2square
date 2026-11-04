<?php

namespace App\Notifications;

use App\Models\TestReport;

class TestReportConfirmedNotification extends DatabaseNotification
{
    public function __construct(
        private readonly TestReport $report,
        private readonly string $confirmerName,
        private readonly ?string $note = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $project = $this->report->project;

        $body = "{$this->confirmerName} reproduced “{$this->report->title}” on {$project->title}.";
        if ($this->note !== null && trim($this->note) !== '') {
            $body .= " Note: {$this->note}";
        }

        return [
            'kind' => 'test_report_confirmed',
            'title' => 'Your test report was confirmed',
            'body' => $body,
            'url' => route('projects.test-reports.show', [$project, $this->report]),
            'project_id' => $project->id,
            'test_report_id' => $this->report->id,
        ];
    }
}
