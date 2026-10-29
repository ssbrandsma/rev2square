@php($assigneeId = $event->metadata['assignee_id'] ?? null)
@php($known = is_int($assigneeId) ? (($assignees ?? [])[$assigneeId] ?? null) : null)
@php($assigneeName = $event->metadata['assignee'] ?? $known['name'] ?? 'someone')
@if($known !== null)<strong><a href="{{ $known['url'] }}" class="text-emerald-700 hover:text-emerald-600">{{ $assigneeName }}</a></strong>@else<strong>{{ $assigneeName }}</strong>@endif
