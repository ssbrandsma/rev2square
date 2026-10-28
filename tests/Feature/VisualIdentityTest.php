<?php

namespace Tests\Feature;

use App\Enums\PublicationStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisualIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_key_screens_render_without_external_font_requests(): void
    {
        $project = Project::factory()->create(['publication_status' => PublicationStatus::Published]);
        $user = User::factory()->create();

        $pages = [
            $this->get('/'),
            $this->get(route('projects.show', $project)),
            $this->get(route('projects.discussion.index', $project)),
            $this->get($user->profileUrl()),
            $this->actingAs($user)->get(route('dashboard')),
        ];

        foreach ($pages as $response) {
            $response->assertOk();
            $this->assertStringNotContainsString('fonts.bunny.net', $response->getContent());
            $this->assertStringNotContainsString('figtree', strtolower($response->getContent()));
        }
    }

    public function test_type_and_focus_tokens_are_present(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // Self-hosted Source Sans 3, needed weights only.
        $this->assertStringContainsString('@fontsource/source-sans-3/400.css', $css);
        $this->assertStringContainsString('@fontsource/source-sans-3/700.css', $css);
        $this->assertStringNotContainsString('800.css', $css);
        $this->assertStringContainsString('--font-sans: "Source Sans 3"', $css);

        // 17px root with relaxed leading, visible keyboard focus.
        $this->assertStringContainsString('font-size: 17px', $css);
        $this->assertStringContainsString(':focus-visible', $css);
    }
}
