@props(['project', 'images'])
@if(! $project->hasRichStory() && ! blank($project->body))
    <p class="mt-1 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">Your existing story is loaded below as paragraphs.</p>
@endif
<p class="mt-1 text-sm text-zinc-500">Text supports <span class="font-mono">**bold**</span>, <span class="font-mono">*italic*</span>, <span class="font-mono">`code`</span>, and <span class="font-mono">[text](https://…)</span>. Images come from this project's library only.</p>
<input type="hidden" name="presentation_version" value="{{ $project->presentation_version }}" />
<div data-story-editor
    data-draft-key="editor-story-{{ $project->id }}"
    data-initial="{{ old('story_document', json_encode($project->hasRichStory() ? $project->story_document : ['version' => 1, 'blocks' => array_map(fn ($p) => ['type' => 'paragraph', 'text' => $p], preg_split('/\n\s*\n/', trim($project->body ?? '')) ?: [])])) }}"
    data-images="{{ json_encode($images->map(fn ($m) => ['id' => $m->id, 'title' => $m->displayTitle(), 'alt' => $m->altText(), 'caption' => $m->caption, 'thumb_url' => $m->isReady() ? route('projects.media.show', [$project, $m, 'variant' => 'thumb']) : null, 'card_url' => $m->isReady() ? route('projects.media.show', [$project, $m, 'variant' => 'card']) : null, 'wide_url' => $m->isReady() ? route('projects.media.show', [$project, $m, 'variant' => 'wide']) : null, 'status' => $m->processing_status])->values()) }}">
    <input type="hidden" name="story_document" value="" />
    <div class="flex flex-wrap items-center gap-2">
        <button type="button" data-undo class="rounded-lg border border-zinc-300 px-2 py-1 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">Undo</button>
        <button type="button" data-redo class="rounded-lg border border-zinc-300 px-2 py-1 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">Redo</button>
        <span class="text-zinc-300">|</span>
        <button type="button" data-insert="**bold**" class="rounded-lg border border-zinc-300 px-2 py-1 text-xs font-bold hover:bg-zinc-50" title="Insert bold">B</button>
        <button type="button" data-insert="*italic*" class="rounded-lg border border-zinc-300 px-2 py-1 text-xs italic hover:bg-zinc-50" title="Insert italic">I</button>
        <button type="button" data-insert="[text](https://)" class="rounded-lg border border-zinc-300 px-2 py-1 font-mono text-xs hover:bg-zinc-50" title="Insert link">Link</button>
        <button type="button" data-preview-toggle class="rounded-lg border border-zinc-300 px-2 py-1 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">Preview</button>
        <span data-save-state class="ml-auto text-xs text-zinc-500">Saved</span>
    </div>
    <div data-editor-pane class="mt-3">
        <ul data-blocks class="space-y-3"></ul>
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach([['paragraph', '+ Paragraph'], ['heading', '+ Heading'], ['list', '+ List'], ['quote', '+ Quote'], ['code', '+ Code'], ['image', '+ Image']] as [$type, $label])
                <button type="button" data-add-block="{{ $type }}" class="rounded-lg border border-dashed border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">{{ $label }}</button>
            @endforeach
        </div>
    </div>
    <div data-preview hidden class="story-body mt-3 max-w-[70ch] space-y-4 rounded-lg bg-zinc-50 p-4 leading-relaxed"></div>
    <div data-image-picker hidden class="mt-3 rounded-xl border border-zinc-200 bg-zinc-50 p-4">
        <p class="text-sm font-semibold">Choose a project image</p>
        @if($images->isEmpty())
            <p data-picker-empty class="mt-1 text-sm text-zinc-500">No images yet — upload one in the Cover section first.</p>
        @else
            <ul class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                @foreach($images as $image)
                    <li>
                        <button type="button" data-pick="{{ $image->id }}" class="block w-full overflow-hidden rounded-lg border border-zinc-200 bg-white hover:border-emerald-500" @disabled(! $image->isReady())>
                            @if($image->isReady())
                                <img src="{{ route('projects.media.show', [$project, $image, 'variant' => 'thumb']) }}" alt="" loading="lazy" class="aspect-square w-full object-cover" />
                            @elseif($image->isFailed())
                                <span class="flex aspect-square flex-col items-center justify-center gap-1 bg-red-50 px-1 text-center text-xs font-semibold text-red-700">Failed<span class="font-normal text-red-600">Retry in the media library</span></span>
                            @else
                                <span class="flex aspect-square items-center justify-center text-xs text-zinc-500">Processing…</span>
                            @endif
                            <span class="block truncate px-1 py-1 text-xs">{{ $image->displayTitle() }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
        <button type="button" data-picker-close class="mt-2 text-sm font-semibold text-zinc-600 hover:text-zinc-900">Cancel</button>
    </div>
</div>
@error('story_document')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
