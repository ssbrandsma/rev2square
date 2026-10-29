@props(['project'])
<section>
    <h2 class="text-sm font-semibold text-zinc-500">Project story</h2>
    @if($project->hasRichStory())
        <div class="story-body mt-3 max-w-[70ch] space-y-4 leading-relaxed text-zinc-700">
            {!! \App\Services\Media\StoryRenderer::toHtml($project->story_document, $project) !!}
        </div>
    @else
        <div class="mt-3 max-w-[70ch] space-y-3 leading-relaxed text-zinc-700">
            @foreach(preg_split('/\n\s*\n/', trim($project->body ?? '')) ?: [] as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
            @if(blank($project->body))
                <p class="text-zinc-500">The maintainer has not written the project story yet.</p>
            @endif
        </div>
    @endif
</section>
