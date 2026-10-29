<x-app-layout>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">Edit project</p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $project->title }}</h1>
    </x-slot>
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        @if(session('status'))
            <p class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</p>
        @endif
        @if(session('error'))
            <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</p>
        @endif

        <nav aria-label="Edit sections" class="mb-6 flex flex-wrap gap-1 border-b border-zinc-200 pb-3 text-sm">
            @foreach([['basics', 'Basics'], ['cover', 'Cover'], ['story', 'Story'], ['library', 'Media library'], ['gallery', 'Gallery'], ['files', 'Files']] as [$anchor, $label])
                <a href="#{{ $anchor }}" class="rounded-lg px-3 py-2 font-semibold text-zinc-600 hover:bg-zinc-100">{{ $label }}</a>
            @endforeach
        </nav>

        <section id="basics" aria-label="Basics" class="scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            <h2 class="font-semibold">Basics</h2>
            <form method="POST" action="{{ route('projects.update', $project) }}" class="mt-4">
                @csrf
                @method('PUT')
                @include('projects._form', ['project' => $project, 'hideBody' => $project->hasRichStory()])
                @if($project->hasRichStory())
                    <p class="mt-3 rounded-lg bg-zinc-100 px-3 py-2 text-sm text-zinc-600">The story is managed in the rich editor below; it is no longer edited as plain text here.</p>
                @endif
                <div class="mt-6 flex items-center justify-end gap-3 border-t border-zinc-100 pt-6">
                    <a href="{{ route('projects.show', $project) }}" class="text-sm font-semibold text-zinc-600 hover:text-zinc-900">Cancel</a>
                    <button type="submit" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Save basics</button>
                </div>
            </form>
        </section>

        <section id="cover" aria-label="Cover image" class="mt-6 scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            <h2 class="font-semibold">Cover image</h2>
            <p class="mt-1 text-sm text-zinc-500">Optional. Shown on cards, the homepage, and the project header. JPEG, PNG, or WebP, max 12 MB.</p>
            @if($project->cover)
                <div class="mt-4" data-focal>
                    <p class="text-sm font-semibold">Current cover — click to set the focal point</p>
                    <div data-focal-picker tabindex="0" role="slider" aria-label="Cover focal point" aria-valuetext="Focal point"
                        class="relative mt-2 max-w-md cursor-crosshair overflow-hidden rounded-xl border border-zinc-200 focus-visible:outline-2 focus-visible:outline-emerald-600">
                        @if($project->cover->isReady())
                            <img src="{{ route('projects.media.show', [$project, $project->cover, 'variant' => 'card']) }}" alt="" class="block w-full" />
                        @elseif($project->cover->isFailed())
                            <p class="bg-red-50 p-6 text-sm font-semibold text-red-700">Cover processing failed — use “Retry processing” in the media library.</p>
                        @else
                            <p class="p-6 text-sm text-zinc-500">Processing…</p>
                        @endif
                        <span data-focal-marker aria-hidden="true" class="absolute h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-emerald-600" style="left: {{ $project->cover->focal_x }}%; top: {{ $project->cover->focal_y }}%;"></span>
                    </div>
                    <form method="POST" action="{{ route('projects.media.update', [$project, $project->cover]) }}" class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="focal_x" value="{{ $project->cover->focal_x }}" />
                        <input type="hidden" name="focal_y" value="{{ $project->cover->focal_y }}" />
                        <button type="submit" class="rounded-lg border border-zinc-300 px-3 py-1.5 font-semibold text-zinc-700 hover:bg-zinc-50">Save focal point</button>
                    </form>
                    <form method="POST" action="{{ route('projects.media.cover.destroy', $project) }}" class="mt-2" onsubmit="return confirm('Remove the cover? The image stays in the library.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm font-semibold text-red-600 hover:text-red-500">Remove cover</button>
                    </form>
                </div>
            @else
                <p class="mt-3 text-sm text-zinc-500">No cover yet. Upload an image below, then choose it as the cover.</p>
            @endif
            <form method="POST" action="{{ route('projects.media.store-image', $project) }}" enctype="multipart/form-data" class="mt-4 border-t border-zinc-100 pt-4">
                @csrf
                <label for="cover-upload" class="text-sm font-semibold">Upload images</label>
                <input id="cover-upload" name="images[]" type="file" multiple required accept=".jpg,.jpeg,.png,.webp" class="mt-1 block w-full text-sm text-zinc-700 file:mr-3 file:rounded-lg file:border file:border-zinc-300 file:bg-zinc-50 file:px-3 file:py-2 file:text-sm file:font-semibold" />
                <button type="submit" class="mt-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Upload</button>
            </form>
        </section>

        <section id="story" aria-label="Story editor" class="mt-6 scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            <h2 class="font-semibold">Story</h2>
            @if(! $project->hasRichStory() && ! blank($project->body))
                <p class="mt-1 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">Your existing story is loaded below as paragraphs. Saving upgrades it to the rich format; no text is lost.</p>
            @endif
            <p class="mt-1 text-sm text-zinc-500">Text supports <span class="font-mono">**bold**</span>, <span class="font-mono">*italic*</span>, <span class="font-mono">`code`</span>, and <span class="font-mono">[text](https://…)</span>. Images come from this project's library only.</p>
            <form method="POST" action="{{ route('projects.story.update', $project) }}" class="mt-4">
                @csrf
                @method('PATCH')
                <input type="hidden" name="presentation_version" value="{{ $project->presentation_version }}" />
                <div data-story-editor
                    data-initial="{{ old('story_document', json_encode($project->hasRichStory() ? $project->story_document : ['version' => 1, 'blocks' => array_map(fn ($p) => ['type' => 'paragraph', 'text' => $p], preg_split('/\n\s*\n/', trim($project->body ?? '')) ?: [])])) }}"
                    data-images="{{ json_encode($images->map(fn ($m) => ['id' => $m->id, 'title' => $m->displayTitle(), 'alt' => $m->altText(), 'caption' => $m->caption, 'thumb_url' => $m->isReady() ? route('projects.media.show', [$project, $m, 'variant' => 'thumb']) : null])->values()) }}">
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
                            <p class="mt-1 text-sm text-zinc-500">No images yet — upload one in the Cover section first.</p>
                        @else
                            <ul class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4">
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
                <div class="mt-4 flex items-center justify-end gap-3 border-t border-zinc-100 pt-4">
                    <button type="submit" data-save class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Save story</button>
                </div>
            </form>
        </section>

        <section id="library" aria-label="Media library" class="mt-6 scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            <h2 class="font-semibold">Media library ({{ $images->count() }})</h2>
            @if($images->isEmpty())
                <p class="mt-2 text-sm text-zinc-500">No images yet. Upload above to use images as the cover, in the story, or in the gallery.</p>
            @else
                <ul class="mt-3 space-y-4">
                    @foreach($images as $image)
                        <li class="rounded-xl border border-zinc-200 p-3">
                            <div class="flex flex-wrap items-start gap-3">
                                @if($image->isReady())
                                    <img src="{{ route('projects.media.show', [$project, $image, 'variant' => 'thumb']) }}" alt="" loading="lazy" class="h-16 w-16 rounded-lg border border-zinc-200 object-cover" />
                                @else
                                    <span class="flex h-16 w-16 items-center justify-center rounded-lg border border-zinc-200 bg-zinc-50 text-xs text-zinc-500">{{ $image->processing_status === 'failed' ? 'Failed' : 'Processing…' }}</span>
                                @endif
                                <div class="min-w-0 flex-1 text-sm">
                                    <p class="font-semibold">{{ $image->displayTitle() }}</p>
                                    <p class="text-xs text-zinc-500">{{ $image->original_name }} · {{ $image->width }}×{{ $image->height }} · {{ $image->processing_status }}</p>
                                    @if($image->isFailed())
                                        <p class="mt-1 text-xs text-red-700">{{ $image->processingErrorMessage() }}</p>
                                    @endif
                                    <p class="mt-1 flex flex-wrap gap-1 text-xs">
                                        @if($project->cover_media_id === $image->id)<span class="rounded-full bg-emerald-100 px-2 py-0.5 font-semibold text-emerald-900">Cover</span>@endif
                                        @if(in_array($image->id, $storyMediaIds, true))<span class="rounded-full bg-sky-100 px-2 py-0.5 font-semibold text-sky-900">In story</span>@endif
                                        @if($image->in_gallery)<span class="rounded-full bg-zinc-100 px-2 py-0.5 font-semibold text-zinc-700">Gallery</span>@endif
                                    </p>
                                </div>
                            </div>
                            <details class="mt-2 text-sm">
                                <summary class="cursor-pointer font-semibold text-emerald-700">Details</summary>
                                <form method="POST" action="{{ route('projects.media.update', [$project, $image]) }}" class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    @csrf
                                    @method('PATCH')
                                    <label class="block text-xs">Title<input name="title" value="{{ $image->title }}" maxlength="255" class="mt-1 block w-full rounded-lg border-zinc-300 px-2 py-1.5 text-sm" /></label>
                                    <label class="block text-xs">Alt text<input name="alt" value="{{ $image->alt }}" maxlength="500" class="mt-1 block w-full rounded-lg border-zinc-300 px-2 py-1.5 text-sm" /></label>
                                    <label class="block text-xs sm:col-span-2">Caption<input name="caption" value="{{ $image->caption }}" maxlength="500" class="mt-1 block w-full rounded-lg border-zinc-300 px-2 py-1.5 text-sm" /></label>
                                    <label class="block text-xs">Credit<input name="credit" value="{{ $image->credit }}" maxlength="255" class="mt-1 block w-full rounded-lg border-zinc-300 px-2 py-1.5 text-sm" /></label>
                                    <label class="block text-xs">Source/license<input name="license" value="{{ $image->license }}" maxlength="255" class="mt-1 block w-full rounded-lg border-zinc-300 px-2 py-1.5 text-sm" /></label>
                                    <label class="flex items-center gap-2 text-xs sm:col-span-2"><input type="checkbox" name="in_gallery" value="1" @checked($image->in_gallery) class="rounded border-zinc-300" /> Show in gallery</label>
                                    <div class="sm:col-span-2"><button type="submit" class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">Save details</button></div>
                                </form>
                            </details>
                            <div class="mt-2 flex flex-wrap gap-3 text-xs">
                                @if($image->isRetryable())
                                    <form method="POST" action="{{ route('projects.media.retry', [$project, $image]) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="font-semibold text-emerald-700 hover:text-emerald-600">Retry processing</button>
                                    </form>
                                @endif
                                @if($project->cover_media_id !== $image->id && $image->isReady())
                                    <form method="POST" action="{{ route('projects.media.cover', [$project, $image]) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="font-semibold text-emerald-700 hover:text-emerald-600">Set as cover</button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('projects.media.destroy', [$project, $image]) }}" class="inline" onsubmit="return confirm('Delete this image?{{ in_array($image->id, $storyMediaIds, true) ? ' It is used in the story and cannot be deleted until removed there.' : '' }}');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="font-semibold text-red-600 hover:text-red-500">Delete</button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section id="gallery" aria-label="Gallery order" class="mt-6 scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 sm:p-8" data-gallery-order>
            <h2 class="font-semibold">Gallery</h2>
            <p class="mt-1 text-sm text-zinc-500">Tick “Show in gallery” above, then order the photos here.</p>
            @php($galleryItems = $images->where('in_gallery', true)->sortBy('gallery_order')->values())
            @if($galleryItems->isEmpty())
                <p class="mt-2 text-sm text-zinc-500">No gallery photos yet.</p>
            @else
                <form method="POST" action="{{ route('projects.media.reorder', $project) }}" class="mt-3">
                    @csrf
                    <ul data-order-list class="space-y-2">
                        @foreach($galleryItems as $item)
                            <li data-order-id="{{ $item->id }}" class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm">
                                <input type="hidden" name="order[]" value="{{ $item->id }}" />
                                <span class="font-semibold">{{ $item->displayTitle() }}</span>
                                <span class="ml-auto flex gap-1">
                                    <button type="button" data-move="up" class="rounded-lg border border-zinc-300 px-2 py-1 text-xs font-semibold" aria-label="Move up">↑</button>
                                    <button type="button" data-move="down" class="rounded-lg border border-zinc-300 px-2 py-1 text-xs font-semibold" aria-label="Move down">↓</button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    <button type="submit" class="mt-3 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Save order</button>
                </form>
            @endif
        </section>

        <section id="files" aria-label="Supporting files" class="mt-6 scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-6 sm:p-8">
            <h2 class="font-semibold">Supporting files</h2>
            <p class="mt-1 text-sm text-zinc-500">PDF, TXT, LOG, Markdown, CSV, or JSON — max 25 MB each. Research firmware/captures (ELF/PCAP) stay in the private research-artifact flow, not here.</p>
            <form method="POST" action="{{ route('projects.media.store-file', $project) }}" enctype="multipart/form-data" class="mt-3 border-b border-zinc-100 pb-4">
                @csrf
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <label class="block flex-1 text-sm font-semibold">Files
                        <input name="files[]" type="file" multiple required accept=".pdf,.txt,.log,.md,.markdown,.csv,.json" class="mt-1 block w-full text-sm text-zinc-700 file:mr-3 file:rounded-lg file:border file:border-zinc-300 file:bg-zinc-50 file:px-3 file:py-2 file:text-sm file:font-semibold" />
                    </label>
                    <label class="block text-sm font-semibold">Category
                        <select name="category" class="mt-1 block rounded-lg border-zinc-300 px-3 py-2 text-sm">
                            <option value="documentation">Documentation</option>
                            <option value="schematics">Schematics</option>
                            <option value="other">Other files</option>
                        </select>
                    </label>
                    <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Attach</button>
                </div>
            </form>
            @if($files->isEmpty())
                <p class="mt-3 text-sm text-zinc-500">No supporting files yet.</p>
            @else
                <ul class="mt-3 space-y-2 text-sm">
                    @foreach($files as $file)
                        <li class="flex flex-wrap items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2">
                            <a href="{{ route('projects.media.download', [$project, $file]) }}" class="font-semibold text-emerald-700">{{ $file->displayTitle() }}</a>
                            <span class="text-xs text-zinc-500">{{ $file->category }} · {{ $file->original_name }} · {{ number_format($file->size / 1024, 1) }} KB</span>
                            <form method="POST" action="{{ route('projects.media.destroy', [$project, $file]) }}" class="ml-auto inline" onsubmit="return confirm('Delete this file?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs font-semibold text-red-600 hover:text-red-500">Delete</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="mt-6 rounded-xl border border-zinc-200 bg-white p-6">
            <h2 class="text-sm font-semibold text-zinc-500">Publication checklist</h2>
            <p class="mt-1 text-sm text-zinc-500">Early-stage publishing is welcome — this list only shows what visitors will (and will not) find. Nothing here blocks publishing.</p>
            @php($hasDevice = $project->devices()->exists())
            @php($hasEvidence = $project->repositories()->where('import_status', 'ready')->exists() || $project->observations()->exists() || $project->testReports()->exists())
            @php($openTasks = $project->tasks()->active()->count())
            <ul class="mt-3 space-y-2 text-sm">
                <li>{{ $project->pitch && ($project->body || $project->hasRichStory()) ? '✓' : '○' }} <span class="font-semibold">Purpose written</span> <span class="text-zinc-500">— pitch and story explain what this revival is about.</span></li>
                <li>{{ $hasDevice ? '✓' : '○' }} <span class="font-semibold">Device described</span> <span class="text-zinc-500">— {{ $hasDevice ? 'a device is linked.' : 'no device linked yet; the page says so explicitly.' }}</span></li>
                <li>{{ $hasEvidence ? '✓' : '○' }} <span class="font-semibold">Evidence started</span> <span class="text-zinc-500">— {{ $hasEvidence ? 'repository evidence or human sources exist.' : 'no evidence yet; import a repository or share an observation.' }}</span></li>
                <li>✓ <span class="font-semibold">Maintainer identity</span> <span class="text-zinc-500">— published as {{ $project->owner->name }}.</span></li>
                <li>{{ $openTasks > 0 ? '✓' : '○' }} <span class="font-semibold">Next steps visible</span> <span class="text-zinc-500">— {{ $openTasks > 0 ? $openTasks.' active task(s) for contributors.' : 'no active tasks yet.' }}</span></li>
            </ul>
            <p class="mt-3 text-sm"><a href="{{ route('projects.show', $project) }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Preview the public page →</a></p>
        </section>
        <form method="POST" action="{{ route('projects.destroy', $project) }}" class="mt-4" onsubmit="return confirm('Delete this project and its milestones and tasks?');">
            @csrf
            @method('DELETE')
            <button type="submit" class="text-sm font-semibold text-red-600 hover:text-red-500">Delete project</button>
        </form>
    </div>
</x-app-layout>
