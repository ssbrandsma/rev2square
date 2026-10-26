<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $user = request()->user();

        $projects = $user->ownedProjects()
            ->withCount('tasks')
            ->latest()
            ->get()
            ->merge(
                Project::whereHas('members', fn ($query) => $query->where('user_id', $user->id))
                    ->withCount('tasks')
                    ->get()
            )
            ->unique('id');

        return view('dashboard', ['projects' => $projects]);
    }
}
