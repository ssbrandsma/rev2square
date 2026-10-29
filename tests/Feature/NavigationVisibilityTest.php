<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function topNavLink(string $route, string $classes, string $label): string
    {
        return '<a href="'.route($route).'" class="'.$classes.'">'.$label.'</a>';
    }

    public function test_guest_navigation_hides_lounge_and_top_methodology(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            $this->topNavLink('lounge', 'text-sm font-medium text-zinc-300 hover:text-white', 'Lounge'), $html);
        $this->assertStringNotContainsString(
            $this->topNavLink('lounge', 'block py-2 text-sm font-medium text-zinc-200', 'Lounge'), $html);
        $this->assertStringNotContainsString(
            $this->topNavLink('about', 'text-sm font-medium text-zinc-300 hover:text-white', 'Methodology'), $html);
        $this->assertStringNotContainsString(
            $this->topNavLink('about', 'block py-2 text-sm font-medium text-zinc-200', 'Methodology'), $html);

        // Methodology stays reachable from the footer.
        $this->assertStringContainsString(
            $this->topNavLink('about', 'hover:text-white', 'Methodology'), $html);
    }

    public function test_signed_in_navigation_shows_lounge_without_top_methodology(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString(
            $this->topNavLink('lounge', 'text-sm font-medium text-zinc-300 hover:text-white', 'Lounge'), $html);
        $this->assertStringContainsString(
            $this->topNavLink('lounge', 'block py-2 text-sm font-medium text-zinc-200', 'Lounge'), $html);
        $this->assertStringNotContainsString(
            $this->topNavLink('about', 'text-sm font-medium text-zinc-300 hover:text-white', 'Methodology'), $html);
        $this->assertStringNotContainsString(
            $this->topNavLink('about', 'block py-2 text-sm font-medium text-zinc-200', 'Methodology'), $html);

        $this->assertStringContainsString(
            $this->topNavLink('about', 'hover:text-white', 'Methodology'), $html);
    }

    public function test_lounge_route_stays_public(): void
    {
        $this->get(route('lounge'))->assertOk();
    }
}
