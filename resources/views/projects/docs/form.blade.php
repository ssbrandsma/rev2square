<x-app-layout>
    <x-slot name="title">{{ $page ? 'Edit — '.$page->title : 'New page' }} — Documentation — {{ $project->title }}</x-slot>
    <x-slot name="header">
        <p class="text-sm font-semibold text-emerald-700">
            <a href="{{ route('projects.show', $project) }}" class="hover:underline">{{ $project->title }}</a> · <a href="{{ route('projects.docs.index', $project) }}" class="hover:underline">Documentation</a>
        </p>
        <h1 class="mt-1 text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-3xl">{{ $page ? 'Edit page' : 'New documentation page' }}</h1>
    </x-slot>

    @include('projects._nav', ['project' => $project, 'active' => 'docs'])

    <div class="mx-auto w-full max-w-[1500px] px-4 py-10 sm:px-6 lg:px-8">
        @if($errors->any())
            <div data-error-summary role="alert" tabindex="-1" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                <p class="font-semibold">Could not save the page — {{ count($errors->all()) }} {{ count($errors->all()) === 1 ? 'problem' : 'problems' }}. Your draft below is preserved.</p>
                <ul class="mt-1 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if(session('error'))
            <p class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">{{ session('error') }}</p>
        @endif
        @if(session('status'))
            <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
        @endif

        <form id="doc-page-form" method="POST" action="{{ $page ? route('projects.docs.update', [$project, $page]) : route('projects.docs.store', $project) }}" class="space-y-5" data-draft-key="docs-meta-{{ $project->id }}-{{ $page?->id ?? 'new' }}">
            @csrf
            @if($page) @method('PATCH') @endif
            @if($page)<input type="hidden" name="doc_version" value="{{ old('doc_version', $page->current_rev) }}" />@endif
            @error('doc_version')<p class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">{{ $message }}</p>@enderror

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block text-sm font-semibold sm:col-span-2">Title
                    <input id="doc-title" type="text" name="title" required maxlength="200" value="{{ old('title', $page?->title) }}" @if($errors->has('title')) aria-describedby="doc-title-error" aria-invalid="true" @endif class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500" />
                </label>
                @error('title')<p id="doc-title-error" class="-mt-3 text-sm text-red-600 sm:col-span-2">{{ $message }}</p>@enderror
                <label class="block text-sm font-semibold sm:col-span-2">Summary (optional)
                    <input id="doc-summary" type="text" name="summary" maxlength="500" value="{{ old('summary', $page?->summary) }}" @if($errors->has('summary')) aria-describedby="doc-summary-error" aria-invalid="true" @endif class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500" />
                </label>
                @error('summary')<p id="doc-summary-error" class="-mt-3 text-sm text-red-600 sm:col-span-2">{{ $message }}</p>@enderror
                <label class="block text-sm font-semibold">Category
                    <select name="category" @if($errors->has('category')) aria-describedby="doc-category-error" aria-invalid="true" @endif class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        @foreach(\App\Enums\DocCategory::cases() as $case)
                            <option value="{{ $case->value }}" @selected(old('category', $page?->category?->value) === $case->value)>{{ $case->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm font-semibold">Visibility
                    <select name="publication_status" @if($errors->has('publication_status')) aria-describedby="doc-visibility-error" aria-invalid="true" @endif class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <option value="DRAFT" @selected(old('publication_status', $page?->publication_status?->value ?? 'DRAFT') === 'DRAFT')>Draft — managers only</option>
                        <option value="PUBLISHED" @selected(old('publication_status', $page?->publication_status?->value) === 'PUBLISHED')>Published</option>
                    </select>
                </label>
                @error('category')<p id="doc-category-error" class="-mt-3 text-sm text-red-600">{{ $message }}</p>@enderror
                @error('publication_status')<p id="doc-visibility-error" class="-mt-3 text-sm text-red-600">{{ $message }}</p>@enderror
                <label class="block text-sm font-semibold">Position in category
                    <input type="number" name="sort_order" min="0" max="100000" value="{{ old('sort_order', $page?->sort_order ?? 0) }}" @if($errors->has('sort_order')) aria-describedby="doc-sort-error" aria-invalid="true" @endif class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500" />
                </label>
                <label class="block text-sm font-semibold">Revision note (optional)
                    <input type="text" name="rev_note" maxlength="500" value="{{ old('rev_note') }}" placeholder="What changed?" @if($errors->has('rev_note')) aria-describedby="doc-revnote-error" aria-invalid="true" @endif class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500" />
                </label>
                @error('sort_order')<p id="doc-sort-error" class="-mt-3 text-sm text-red-600">{{ $message }}</p>@enderror
                @error('rev_note')<p id="doc-revnote-error" class="-mt-3 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            @include('projects.docs._editor', ['project' => $project, 'page' => $page, 'images' => $images, 'pages' => $pages])

            <div class="flex items-center justify-end gap-3 border-t border-zinc-100 pt-6">
                <a href="{{ $page ? route('projects.docs.show', [$project, $page]) : route('projects.docs.index', $project) }}" class="text-sm font-semibold text-zinc-600 hover:text-zinc-900">Cancel</a>
                <button type="submit" data-save data-disable-on-submit class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">{{ $page ? 'Save page' : 'Create page' }}</button>
            </div>
        </form>

        <form method="POST" action="{{ route('projects.media.store-image', $project) }}" enctype="multipart/form-data" data-doc-upload data-upload-feedback data-main-form="#doc-page-form"
            data-max-files="{{ $uploadLimits['max_files'] }}"
            data-max-bytes="{{ $uploadLimits['max_bytes'] }}"
            data-total-bytes="{{ $uploadLimits['total_bytes'] }}"
            data-extensions="{{ implode(',', $uploadLimits['extensions']) }}"
            data-type-label="{{ $uploadLimits['type_label'] }}"
            class="mt-8 rounded-xl border border-zinc-200 bg-white p-5">
            @csrf
            <p class="text-sm font-semibold">Upload photos while editing</p>
            <p id="upload-limits" class="mt-1 text-sm text-zinc-500">Up to {{ $uploadLimits['max_files'] }} photos per upload · max {{ number_format($uploadLimits['max_bytes'] / 1048576, 0) }} MB each · {{ $uploadLimits['type_label'] }}. Uploads land in the project library and appear in the picker above. Your draft is preserved.</p>
            <input type="hidden" name="title" data-draft-field="title" value="" />
            <input type="hidden" name="summary" data-draft-field="summary" value="" />
            <input type="hidden" name="category" data-draft-field="category" value="" />
            <input type="hidden" name="publication_status" data-draft-field="publication_status" value="" />
            <input type="hidden" name="sort_order" data-draft-field="sort_order" value="" />
            <input type="hidden" name="doc_document" data-draft-field="doc_document" value="" />
            <input type="hidden" name="doc_version" data-draft-field="doc_version" value="" />
            <input type="hidden" name="rev_note" data-draft-field="rev_note" value="" />
            <input type="hidden" name="expected_count" data-expected-count value="0" />
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                <input type="file" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp" aria-describedby="upload-limits" class="block w-full text-sm" />
                <button type="submit" data-disable-on-submit class="shrink-0 rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Upload</button>
            </div>
            <div data-upload-errors role="alert" class="mt-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 {{ $errors->upload->any() ? '' : 'hidden' }}">
                @if($errors->upload->any())
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach($errors->upload->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div data-upload-result role="status" class="hidden"></div>
        </form>
    </div>
</x-app-layout>
