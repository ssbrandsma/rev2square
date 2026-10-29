# {{ $data['title'] }} — revival export

> Exported {{ $data['exported_at'] }}. Private offers, messages, and invitations are never included.

- Phase: {{ $data['phase'] }} · Activity: {{ $data['activity'] }} · Status: {{ $data['publication_status'] }}
@if($data['pitch'])
- Pitch: {{ $data['pitch'] }}
@endif

## Story

{{ $data['story_markdown'] ?? $data['story'] ?? 'No story written yet.' }}

@if(! empty($data['media']))
## Presentation media

@forelse($data['media'] as $item)
- **{{ $item['title'] }}** ({{ $item['kind'] }}/{{ $item['category'] }})@if($item['caption']) — {{ $item['caption'] }}@endif
@empty
@endforelse
@endif

## Devices

@forelse($data['devices'] as $device)
### {{ $device['name'] }}

@if($device['category'])- Category: {{ $device['category'] }}
@endif
@if($device['description']){{ $device['description'] }}

@endif
@foreach($device['variants'] as $variant)
- Variant: rev {{ $variant['hardware_revision'] ?? '?' }}, fw {{ $variant['firmware_version'] ?? '?' }}@if($variant['notes']) — {{ $variant['notes'] }}@endif
@endforeach
@empty
No device linked yet.
@endforelse

## Source repositories

@forelse($data['repositories'] as $repository)
- {{ $repository['url'] }} @ {{ $repository['commit_sha'] ?? 'unknown commit' }} ({{ $repository['import_status'] }})
@empty
No repository imported.
@endforelse

## Roadmap

@forelse($data['milestones'] as $milestone)
### {{ $milestone['title'] }}@if($milestone['retired']) (retired)@endif

- Status: {{ $milestone['status'] }} · Origin: {{ $milestone['origin'] }}@if($milestone['target_date']) · Target: {{ $milestone['target_date'] }}@endif
@if($milestone['description']){{ $milestone['description'] }}

@endif
@empty
No milestones.
@endforelse

## Tasks

@forelse($data['tasks'] as $task)
### {{ $task['title'] }}

- Status: {{ $task['status'] }} · Origin: {{ $task['origin'] }}@if($task['assignee']) · Assignee: {{ $task['assignee'] }}@endif@if($task['milestone']) · Milestone: {{ $task['milestone'] }}@endif
@if(count($task['skills']) > 0)- Skills: {{ implode(', ', $task['skills']) }}
@endif
- Needs hardware: {{ $task['requires_hardware'] ? 'yes' : 'no' }}
@if($task['description']){{ $task['description'] }}

@endif
@if($task['definition_of_done'])- Done means: {{ $task['definition_of_done'] }}
@endif
@empty
No tasks.
@endforelse

## Observations

@forelse($data['observations'] as $observation)
### {{ $observation['title'] }} @if($observation['verified']) — verified hardware claim @if($observation['verified_by']) by {{ $observation['verified_by'] }} @endif @else — unverified @endif

- By {{ $observation['author'] }}, {{ $observation['observed_at'] }}@if($observation['variant']) · {{ $observation['variant'] }}@endif
@if($observation['source_url'])- Reference: {{ $observation['source_url'] }}
@endif
{{ $observation['body'] }}

@empty
No observations.
@endforelse

## Test reports

@forelse($data['test_reports'] as $report)
### {{ $report['title'] }} — {{ $report['outcome'] }} @if($report['confirmed']) (confirmed by {{ $report['confirmed_by'] }}) @endif

- By {{ $report['author'] }}, {{ $report['reported_at'] }}@if($report['variant']) · {{ $report['variant'] }}@endif
@if($report['environment'])- Environment: {{ $report['environment'] }}
@endif
{{ $report['body'] }}

@empty
No test reports.
@endforelse

## Official updates

@forelse($data['updates'] as $update)
### {{ $update['title'] ?? 'Untitled update' }}

- By {{ $update['author'] }}, {{ $update['posted_at'] }} · {{ $update['replies'] }} {{ \Illuminate\Support\Str::plural('reply', $update['replies']) }}
{{ $update['body'] }}

@empty
No official updates.
@endforelse

## Discussion

@forelse($data['discussion'] as $thread)
### {{ $thread['title'] ?? ($thread['announcement'] ? 'Announcement' : 'Discussion thread') }}@if($thread['announcement']) (announcement)@endif

- By {{ $thread['author'] }}, {{ $thread['posted_at'] }} · {{ $thread['replies'] }} {{ \Illuminate\Support\Str::plural('reply', $thread['replies']) }}
{{ $thread['body'] }}

@empty
No discussion threads.
@endforelse

## History

@forelse($data['history'] as $entry)
- {{ $entry['at'] }} — {{ $entry['actor'] }}: {{ $entry['event'] }}@if($entry['subject']) ({{ $entry['subject'] }})@endif
@empty
No recorded history.
@endforelse
