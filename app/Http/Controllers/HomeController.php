<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        $featured = Project::featurable()->with(['devices', 'tasks', 'cover'])->latest()->take(2)->get();

        return view('welcome', ['featured' => $featured]);
    }
}
