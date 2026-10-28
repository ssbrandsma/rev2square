@php($uploadRoute = $uploadRoute ?? null)
<div class="mt-3 border-t border-zinc-100 pt-3">
    <h4 class="text-sm font-semibold text-zinc-500">Attachments</h4>
    @if($attachments->isEmpty())
        <p class="mt-1 text-sm text-zinc-500">No files attached.</p>
    @else
        <ul class="mt-2 space-y-1.5">
            @foreach($attachments as $attachment)
                <li class="flex flex-wrap items-center gap-2 text-sm">
                    <a href="{{ route('projects.attachments.download', [$project, $attachment]) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">{{ $attachment->original_name }}</a>
                    <span class="text-xs text-zinc-500">{{ number_format($attachment->size / 1024, 1) }} KB · {{ $attachment->uploader->name }}</span>
                    @if($attachment->reviewed_at)
                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-900">Reviewed</span>
                    @else
                        <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-semibold text-zinc-700">Unreviewed</span>
                        @can('update', $project)
                            <form method="POST" action="{{ route('projects.attachments.review', [$project, $attachment]) }}" class="inline">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="text-xs font-semibold text-emerald-700 hover:text-emerald-600">Mark reviewed</button>
                            </form>
                        @endcan
                    @endif
                    @if(auth()->check() && ($attachment->user_id === auth()->id() || $project->managedBy(auth()->user())))
                        <form method="POST" action="{{ route('projects.attachments.destroy', [$project, $attachment]) }}" class="inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs font-semibold text-zinc-500 hover:text-zinc-800" onclick="return confirm('Delete this attachment?')">Delete</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
    @auth
        @if($uploadRoute)
            <form method="POST" action="{{ $uploadRoute }}" enctype="multipart/form-data" class="mt-2 flex flex-wrap items-center gap-2">
                @csrf
                <label for="file-{{ $parentId }}" class="sr-only">Attach a log or document</label>
                <input id="file-{{ $parentId }}" name="file" type="file" required
                    accept=".txt,.log,.md,.markdown,.csv,.json,.pdf,.png,.jpg,.jpeg"
                    class="text-sm text-zinc-600">
                <button type="submit" class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">Attach file</button>
            </form>
            <p class="mt-1 text-xs text-zinc-500">Logs and documents only (max 5 MB). Never upload credentials, private keys, serial numbers, or personal data — attachments are visible to everyone who can view this project.</p>
        @endif
    @endauth
</div>
