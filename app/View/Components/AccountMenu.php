<?php

namespace App\View\Components;

use App\Models\Project;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class AccountMenu extends Component
{
    public function render(): View|Closure|string
    {
        return view('components.account-menu');
    }

    /**
     * Bounded recent-project list for the menu: newest owned plus
     * newest active memberships, five at most. Never the full set.
     *
     * @return Collection<int, Project>
     */
    public function recentProjects(): Collection
    {
        $user = auth()->user();

        return $user->ownedProjects()->latest()->take(3)->get()
            ->merge(Project::whereHas('members', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('status', 'active'))->latest()->take(3)->get())
            ->unique('id')
            ->take(5);
    }

    public function unreadCount(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }
}
