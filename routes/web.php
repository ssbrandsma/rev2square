<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewController;
use App\Models\Project;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $featured = Project::published()->with(['devices', 'tasks'])->latest()->take(2)->get();

    return view('welcome', ['featured' => $featured]);
})->name('home');

Route::view('/about', 'about')->name('about');

Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');

Route::middleware('auth')->group(function () {
    Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
});

Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');

Route::get('/projects/{project}/evidence', [EvidenceController::class, 'show'])
    ->middleware('auth')->name('projects.evidence');

Route::get('/projects/{project}/reports/{report}', [ReportController::class, 'show'])
    ->name('projects.reports.show');

Route::middleware('auth')->group(function () {
    Route::post('/projects/{project}/reports/{report}/approve', [ReviewController::class, 'approve'])->name('projects.reports.approve');
    Route::post('/projects/{project}/reports/{report}/reject', [ReviewController::class, 'reject'])->name('projects.reports.reject');
    Route::post('/projects/{project}/reports/{report}/publish', [ReviewController::class, 'publish'])->name('projects.reports.publish');

    Route::get('/projects/{project}/contribute', [OfferController::class, 'create'])->name('projects.offers.create');
    Route::post('/projects/{project}/offers', [OfferController::class, 'store'])->name('projects.offers.store');
    Route::get('/projects/{project}/offers', [OfferController::class, 'index'])->name('projects.offers.index');
    Route::post('/projects/{project}/offers/{offer}/accept', [OfferController::class, 'accept'])->name('projects.offers.accept');
    Route::post('/projects/{project}/offers/{offer}/decline', [OfferController::class, 'decline'])->name('projects.offers.decline');
});

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
