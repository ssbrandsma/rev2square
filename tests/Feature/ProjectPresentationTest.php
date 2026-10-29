<?php

namespace Tests\Feature;

use App\Enums\PublicationStatus;
use App\Models\Device;
use App\Models\Project;
use App\Models\ProjectMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectPresentationTest extends TestCase
{
    use RefreshDatabase;

    /** Synthetic 800x600 JPEG with known provenance (GD-generated). */
    private function jpeg(int $width = 800, int $height = 600): string
    {
        $img = imagecreatetruecolor($width, $height);
        $red = imagecolorallocate($img, 180, 40, 40);
        imagefill($img, 0, 0, $red);
        ob_start();
        imagejpeg($img, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    private function png(int $width = 400, int $height = 300): string
    {
        $img = imagecreatetruecolor($width, $height);
        $blue = imagecolorallocate($img, 40, 80, 180);
        imagefill($img, 0, 0, $blue);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /** Minimal PNG header claiming huge dimensions (bomb probe, 55 bytes). */
    private function bombPng(): string
    {
        $ihdr = pack('N', 10000).pack('N', 10000).chr(8).chr(2)."\0\0\0";
        $chunk = 'IHDR'.$ihdr;

        return "\x89PNG\r\n\x1a\n".pack('N', 13).$chunk.pack('N', crc32($chunk));
    }

    private function seedProject(?User $owner = null): array
    {
        $owner ??= User::factory()->create();
        $project = Project::factory()->draft()->create(['owner_id' => $owner->id]);

        return [$project->fresh(), $owner];
    }

    private function uploadImage(Project $project, User $owner, ?string $bytes = null): ProjectMedia
    {
        $response = $this->actingAs($owner)->post(route('projects.media.store-image', $project), [
            'images' => [UploadedFile::fake()->createWithContent('photo.jpg', $bytes ?? $this->jpeg())],
        ]);
        $response->assertSessionHas('status');

        return ProjectMedia::where('project_id', $project->id)->latest('id')->first();
    }

    public function test_existing_plain_stories_render_unchanged(): void
    {
        [$project, $owner] = $this->seedProject();
        $project->forceFill(['body' => "First paragraph.\n\nSecond <b>literal</b> paragraph."])->save();

        $page = $this->actingAs($owner)->get(route('projects.show', $project));
        $page->assertOk();
        $page->assertSee('First paragraph.');
        $page->assertSee('Second &lt;b&gt;literal&lt;/b&gt; paragraph.', false);
        $this->assertFalse($project->fresh()->hasRichStory());
    }

    public function test_cover_appears_on_cards_header_and_profile(): void
    {
        [$project, $owner] = $this->seedProject();
        $device = Device::factory()->create();
        $project->devices()->attach($device->id);
        $project->forceFill(['publication_status' => PublicationStatus::Published])->save();

        $media = $this->uploadImage($project, $owner);
        $this->actingAs($owner)->post(route('projects.media.cover', [$project, $media]))->assertRedirect();
        $this->assertSame($media->id, $project->fresh()->cover_media_id);

        $url = route('projects.media.show', [$project->fresh(), $media, 'variant' => 'card'], false);

        $show = $this->get(route('projects.show', $project));
        $show->assertOk();
        $show->assertSee($url, false);

        $home = $this->get(route('home'));
        $home->assertOk();
        $home->assertSee($url, false);

        $profile = $this->get($owner->profileUrl());
        $profile->assertOk();
    }

    public function test_focal_point_is_consistent_across_surfaces(): void
    {
        [$project, $owner] = $this->seedProject();
        $media = $this->uploadImage($project, $owner);
        $this->actingAs($owner)->post(route('projects.media.cover', [$project, $media]));
        $this->actingAs($owner)->patch(route('projects.media.update', [$project, $media]), [
            'focal_x' => 20, 'focal_y' => 80,
        ]);

        $this->assertSame('20% 80%', $media->fresh()->focalPosition());

        $show = $this->actingAs($owner)->get(route('projects.show', $project));
        $show->assertSee('object-position: 20% 80%;', false);
    }

    public function test_inline_image_renders_between_paragraphs(): void
    {
        [$project, $owner] = $this->seedProject();
        $media = $this->uploadImage($project, $owner);
        $media->forceFill(['caption' => 'Teardown overview', 'alt' => 'PCB top view'])->save();

        $doc = ['version' => 1, 'blocks' => [
            ['type' => 'paragraph', 'text' => 'Before the photo.'],
            ['type' => 'image', 'media_id' => $media->id, 'layout' => 'normal'],
            ['type' => 'paragraph', 'text' => 'After the photo.'],
        ]];
        $this->actingAs($owner)->patch(route('projects.story.update', $project), [
            'story_document' => json_encode($doc),
            'presentation_version' => $project->fresh()->presentation_version,
        ])->assertRedirect();

        $html = $this->actingAs($owner)->get(route('projects.show', $project))->getContent();
        $before = strpos($html, 'Before the photo.');
        $figure = strpos($html, '<figure class="story-media">');
        $after = strpos($html, 'After the photo.');
        $this->assertNotFalse($before);
        $this->assertNotFalse($figure);
        $this->assertTrue($before < $figure && $figure < $after);
        $this->assertStringContainsString('Teardown overview', $html);
        $this->assertStringContainsString('alt="PCB top view"', $html);
    }

    public function test_rich_blocks_survive_save_and_reload(): void
    {
        [$project, $owner] = $this->seedProject();

        $doc = ['version' => 1, 'blocks' => [
            ['type' => 'heading', 'level' => 2, 'text' => 'Power supply'],
            ['type' => 'paragraph', 'text' => 'It uses **bold** and *italic* and [docs](https://example.com/x).'],
            ['type' => 'list', 'ordered' => false, 'items' => ['One', 'Two']],
            ['type' => 'quote', 'text' => 'Noted.'],
            ['type' => 'code', 'text' => 'gpio --read 4'],
        ]];
        $this->actingAs($owner)->patch(route('projects.story.update', $project), [
            'story_document' => json_encode($doc),
            'presentation_version' => 0,
        ])->assertRedirect();

        $page = $this->actingAs($owner)->get(route('projects.show', $project));
        $page->assertSee('<h3>Power supply</h3>', false);
        $page->assertSee('<strong>bold</strong>', false);
        $page->assertSee('<ul>', false);
        $page->assertSee('<a href="https://example.com/x"', false);

        $edit = $this->actingAs($owner)->get(route('projects.edit', $project));
        $edit->assertOk();
        $edit->assertSee('Power supply', false);
    }

    public function test_failed_story_save_keeps_the_draft(): void
    {
        [$project, $owner] = $this->seedProject();

        $bad = ['version' => 1, 'blocks' => [['type' => 'nope', 'text' => 'x']]];
        $response = $this->actingAs($owner)->patch(route('projects.story.update', $project), [
            'story_document' => json_encode($bad),
            'presentation_version' => 0,
        ]);

        $response->assertSessionHas('error');
        $response->assertSessionHasInput('story_document', json_encode($bad));
        $this->assertFalse($project->fresh()->hasRichStory());
    }

    public function test_gallery_reorder_persists_display_order(): void
    {
        [$project, $owner] = $this->seedProject();
        $first = $this->uploadImage($project, $owner);
        $second = $this->uploadImage($project, $owner, $this->png());

        $this->actingAs($owner)->post(route('projects.media.reorder', $project), [
            'order' => [$second->id, $first->id],
        ])->assertRedirect();

        $this->assertSame(0, $second->fresh()->gallery_order);
        $this->assertSame(1, $first->fresh()->gallery_order);

        $html = $this->actingAs($owner)->get(route('projects.show', $project))->getContent();
        $this->assertTrue(strpos($html, 'data-gallery-open="'.$second->id.'"') < strpos($html, 'data-gallery-open="'.$first->id.'"'));
    }

    public function test_supporting_files_download_safely(): void
    {
        [$project, $owner] = $this->seedProject();

        $pdf = "%PDF-1.4\n%test\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n";
        $response = $this->actingAs($owner)->post(route('projects.media.store-file', $project), [
            'files' => [UploadedFile::fake()->createWithContent('schematic.pdf', $pdf, 'application/pdf')],
            'category' => 'schematics',
        ]);
        $response->assertSessionHas('status');

        $file = ProjectMedia::where('project_id', $project->id)->first();
        $this->assertSame('schematics', $file->category);

        $download = $this->actingAs($owner)->get(route('projects.media.download', [$project, $file]));
        $download->assertOk();
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
    }

    public function test_same_project_dedupes_but_cross_project_does_not_probe(): void
    {
        [$project, $owner] = $this->seedProject();
        $bytes = $this->jpeg();

        $first = $this->uploadImage($project, $owner, $bytes);
        $second = $this->uploadImage($project, $owner, $bytes);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ProjectMedia::where('project_id', $project->id)->count());

        [$other] = $this->seedProject(User::factory()->create());
        $otherMedia = $this->uploadImage($other, $other->owner, $bytes);
        $this->assertNotSame($first->id, $otherMedia->id);
    }

    public function test_story_image_cannot_be_deleted_but_cover_can_unlink(): void
    {
        [$project, $owner] = $this->seedProject();
        $media = $this->uploadImage($project, $owner);

        $this->actingAs($owner)->post(route('projects.media.cover', [$project, $media]));
        $doc = ['version' => 1, 'blocks' => [
            ['type' => 'image', 'media_id' => $media->id, 'layout' => 'normal'],
        ]];
        $this->actingAs($owner)->patch(route('projects.story.update', $project), [
            'story_document' => json_encode($doc),
            'presentation_version' => $project->fresh()->presentation_version,
        ]);

        $blocked = $this->actingAs($owner)->delete(route('projects.media.destroy', [$project, $media]));
        $blocked->assertSessionHas('error');
        $this->assertNotNull(ProjectMedia::find($media->id));

        // Cover-only images unlink cleanly on delete.
        $cover = $this->uploadImage($project, $owner, $this->png());
        $this->actingAs($owner)->post(route('projects.media.cover', [$project, $cover]));
        $this->actingAs($owner)->delete(route('projects.media.destroy', [$project, $cover]))->assertRedirect();
        $this->assertNull(ProjectMedia::find($cover->id));
        $this->assertNull($project->fresh()->cover_media_id);
    }

    public function test_strangers_cannot_touch_draft_media(): void
    {
        [$project, $owner] = $this->seedProject();
        $media = $this->uploadImage($project, $owner);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('projects.media.store-image', $project), [
            'images' => [UploadedFile::fake()->createWithContent('x.jpg', $this->jpeg())],
        ])->assertForbidden();

        $this->actingAs($stranger)->post(route('projects.media.reorder', $project), [
            'order' => [$media->id],
        ])->assertForbidden();

        $this->actingAs($stranger)->get(route('projects.media.show', [$project, $media]))->assertForbidden();
        $this->actingAs($stranger)->get(route('projects.edit', $project))->assertForbidden();
    }

    public function test_cross_project_media_ids_are_rejected(): void
    {
        [$project, $owner] = $this->seedProject();
        [$other, $otherOwner] = $this->seedProject();
        $foreign = $this->uploadImage($other, $otherOwner);

        $doc = ['version' => 1, 'blocks' => [
            ['type' => 'image', 'media_id' => $foreign->id, 'layout' => 'normal'],
        ]];
        $response = $this->actingAs($owner)->patch(route('projects.story.update', $project), [
            'story_document' => json_encode($doc),
            'presentation_version' => 0,
        ]);
        $response->assertSessionHas('error');
        $this->assertFalse($project->fresh()->hasRichStory());

        $reorder = $this->actingAs($owner)->post(route('projects.media.reorder', $project), [
            'order' => [$foreign->id],
        ]);
        $reorder->assertSessionHas('error');

        $this->actingAs($owner)->get(route('projects.media.show', [$project, $foreign]))->assertNotFound();
    }

    public function test_draft_media_is_private_but_published_is_public(): void
    {
        [$project, $owner] = $this->seedProject();
        $media = $this->uploadImage($project, $owner);
        $this->post(route('logout'));

        $this->get(route('projects.media.show', [$project, $media]))->assertForbidden();

        $project->forceFill(['publication_status' => PublicationStatus::Published])->save();
        $this->get(route('projects.media.show', [$project, $media]))->assertOk();

        $project->forceFill(['publication_status' => PublicationStatus::Draft])->save();
        $this->get(route('projects.media.show', [$project, $media]))->assertForbidden();
    }

    public function test_malicious_markup_and_filenames_stay_inert(): void
    {
        [$project, $owner] = $this->seedProject();

        $doc = ['version' => 1, 'blocks' => [
            ['type' => 'paragraph', 'text' => 'Hello <script>alert(1)</script> <img src=x onerror=alert(2)>'],
            ['type' => 'paragraph', 'text' => '[click](javascript:alert(3)) and [creds](https://user:pass@example.com/)'],
        ]];
        $this->actingAs($owner)->patch(route('projects.story.update', $project), [
            'story_document' => json_encode($doc),
            'presentation_version' => 0,
        ])->assertRedirect();

        $html = $this->actingAs($owner)->get(route('projects.show', $project))->getContent();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<a href="javascript:', $html);
        $this->assertStringContainsString('[click](javascript:alert(3))', $html);
        $this->assertStringContainsString('[creds](https://user:pass@example.com/)', $html);

        $this->actingAs($owner)->post(route('projects.media.store-file', $project), [
            'files' => [UploadedFile::fake()->createWithContent('<img src=x onerror=alert(1)>.txt', 'hello', 'text/plain')],
        ]);
        $page = $this->actingAs($owner)->get(route('projects.show', $project))->getContent();
        $this->assertStringNotContainsString('<img src=x', $page);
    }

    public function test_oversized_malformed_and_huge_images_fail_gracefully(): void
    {
        [$project, $owner] = $this->seedProject();

        // Not an image at all.
        $bad = $this->actingAs($owner)->post(route('projects.media.store-image', $project), [
            'images' => [UploadedFile::fake()->createWithContent('fake.jpg', 'not image bytes', 'text/plain')],
        ]);
        $bad->assertSessionHas('error');
        $this->assertSame(0, ProjectMedia::where('project_id', $project->id)->count());

        // Declared dimensions beyond the pixel budget (tiny probe file).
        $bomb = $this->actingAs($owner)->post(route('projects.media.store-image', $project), [
            'images' => [UploadedFile::fake()->createWithContent('bomb.png', $this->bombPng(), 'image/png')],
        ]);
        $bomb->assertSessionHas('error');
        $this->assertSame(0, ProjectMedia::where('project_id', $project->id)->count());

        // SVG is not an allowlisted raster format.
        $svg = $this->actingAs($owner)->post(route('projects.media.store-image', $project), [
            'images' => [UploadedFile::fake()->createWithContent('evil.svg', '<svg onload=alert(1)></svg>', 'image/svg+xml')],
        ]);
        $svg->assertSessionHas('error');
        $this->assertSame(0, ProjectMedia::where('project_id', $project->id)->count());
    }

    public function test_exif_is_stripped_and_output_is_clean_jpeg(): void
    {
        [$project, $owner] = $this->seedProject();

        // JPEG with a real APP1 EXIF segment (Orientation 6).
        $jpeg = $this->jpeg(600, 400);
        $tiff = "II*\0".pack('V', 8).pack('v', 1).pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', 6)."\0\0".pack('V', 0);
        $app1 = "\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\0\0".$tiff;
        $withExif = "\xFF\xD8".$app1.substr($jpeg, 2);

        if (function_exists('exif_read_data')) {
            $this->assertSame(6, (int) (@exif_read_data('data://image/jpeg;base64,'.base64_encode($withExif))['Orientation'] ?? 0));
        }

        $media = $this->uploadImage($project, $owner, $withExif);
        $media = $media->fresh();

        $this->assertSame('ready', $media->processing_status);
        $this->assertSame('image/jpeg', $media->mime);
        // Orientation 6 rotates portrait: 600x400 becomes 400x600.
        $this->assertSame(400, $media->width);
        $this->assertSame(600, $media->height);

        $stored = Storage::disk('local')->get($media->originalPath());
        $this->assertStringNotContainsString('Exif', substr($stored, 0, 64));
        if (function_exists('exif_read_data')) {
            // GD writes only a COMMENT segment; no EXIF/APP1, GPS, or
            // orientation data may survive re-encoding.
            $meta = @exif_read_data('data://image/jpeg;base64,'.base64_encode($stored));
            $this->assertIsArray($meta);
            $this->assertStringNotContainsString('EXIF', (string) ($meta['SectionsFound'] ?? ''));
            $this->assertArrayNotHasKey('GPS', $meta);
            $this->assertArrayNotHasKey('Orientation', $meta);
        }
    }

    public function test_concurrent_story_edits_do_not_overwrite(): void
    {
        [$project, $owner] = $this->seedProject();

        $first = ['version' => 1, 'blocks' => [['type' => 'paragraph', 'text' => 'First writer.']]];
        $this->actingAs($owner)->patch(route('projects.story.update', $project), [
            'story_document' => json_encode($first),
            'presentation_version' => 0,
        ])->assertRedirect();
        $this->assertSame(1, $project->fresh()->presentation_version);

        // Stale second writer is refused; newer content survives.
        $second = ['version' => 1, 'blocks' => [['type' => 'paragraph', 'text' => 'Stale writer.']]];
        $response = $this->actingAs($owner)->patch(route('projects.story.update', $project), [
            'story_document' => json_encode($second),
            'presentation_version' => 0,
        ]);
        $response->assertSessionHas('error');
        $response->assertSessionHasInput('story_document', json_encode($second));

        $page = $this->actingAs($owner)->get(route('projects.show', $project))->getContent();
        $this->assertStringContainsString('First writer.', $page);
        $this->assertStringNotContainsString('Stale writer.', $page);
    }

    public function test_exports_include_story_and_media_metadata_safely(): void
    {
        [$project, $owner] = $this->seedProject();
        $media = $this->uploadImage($project, $owner);
        $media->forceFill(['caption' => 'Board photo'])->save();

        $doc = ['version' => 1, 'blocks' => [
            ['type' => 'heading', 'level' => 2, 'text' => 'Teardown'],
            ['type' => 'image', 'media_id' => $media->id, 'layout' => 'normal'],
        ]];
        $this->actingAs($owner)->patch(route('projects.story.update', $project), [
            'story_document' => json_encode($doc),
            'presentation_version' => $project->fresh()->presentation_version,
        ]);

        $md = $this->actingAs($owner)->get(route('projects.export', [$project, 'md']));
        $md->assertOk();
        $md->assertSee('## Teardown', false);
        $md->assertSee('Board photo', false);

        $json = $this->actingAs($owner)->get(route('projects.export', [$project, 'json']));
        $json->assertOk();
        $payload = $json->json();
        $this->assertSame('rich', $payload['story_format']);
        $this->assertSame(1, $payload['story_document']['version']);
        $this->assertStringNotContainsString('storage_key', $json->getContent());
        $this->assertStringNotContainsString('media/', $json->getContent());
    }
}
