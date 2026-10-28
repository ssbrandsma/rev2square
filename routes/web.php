<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\ArchitectureController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscussionController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\MilestoneController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\ObservationController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SavedProjectController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TestReportController;
use App\Models\Project;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $featured = Project::featurable()->with(['devices', 'tasks'])->latest()->take(2)->get();

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
Route::get('/projects/{project}/architecture', [ArchitectureController::class, 'show'])
    ->name('projects.architecture.show');
Route::post('/projects/{project}/architecture', [ArchitectureController::class, 'store'])
    ->middleware('auth')->name('projects.architecture.store');
Route::get('/projects/{project}/agent', [AgentController::class, 'index'])
    ->middleware('auth')->name('projects.agent.index');
Route::post('/projects/{project}/agent', [AgentController::class, 'store'])
    ->middleware('auth')->name('projects.agent.store');
Route::get('/projects/{project}/agent/{conversation}', [AgentController::class, 'show'])
    ->middleware('auth')->name('projects.agent.show');
Route::post('/projects/{project}/agent/{conversation}/messages', [AgentController::class, 'message'])
    ->middleware('auth')->name('projects.agent.message');
Route::post('/projects/{project}/agent/messages/{message}/save', [AgentController::class, 'save'])
    ->middleware('auth')->name('projects.agent.save');

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
    Route::post('/projects/{project}/offers/{offer}/withdraw', [OfferController::class, 'withdraw'])->name('projects.offers.withdraw');
    Route::get('/projects/{project}/offers/{offer}', [OfferController::class, 'show'])->name('projects.offers.show');
    Route::post('/projects/{project}/offers/{offer}/message', [OfferController::class, 'message'])->name('projects.offers.message');
    Route::post('/projects/{project}/offers/{offer}/reopen', [OfferController::class, 'reopen'])->name('projects.offers.reopen');
    Route::post('/projects/{project}/offers/{offer}/join', [OfferController::class, 'join'])->name('projects.offers.join');
    Route::post('/projects/{project}/save', [SavedProjectController::class, 'store'])->name('projects.save');
    Route::delete('/projects/{project}/save', [SavedProjectController::class, 'destroy'])->name('projects.unsave');

    Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::patch('/inbox/read-all', [InboxController::class, 'readAll'])->name('inbox.read-all');
    Route::get('/inbox/{id}', [InboxController::class, 'read'])->name('inbox.read');

    Route::get('/projects/{project}/tasks/create', [TaskController::class, 'create'])->name('projects.tasks.create');
    Route::post('/projects/{project}/tasks', [TaskController::class, 'store'])->name('projects.tasks.store');
    Route::get('/projects/{project}/tasks/{task}/edit', [TaskController::class, 'edit'])->name('projects.tasks.edit');
    Route::patch('/projects/{project}/tasks/{task}', [TaskController::class, 'update'])->name('projects.tasks.update');
    Route::patch('/projects/{project}/tasks/{task}/transition', [TaskController::class, 'transition'])->name('projects.tasks.transition');
    Route::patch('/projects/{project}/tasks/{task}/assign', [TaskController::class, 'assign'])->name('projects.tasks.assign');

    Route::get('/projects/{project}/milestones/create', [MilestoneController::class, 'create'])->name('projects.milestones.create');
    Route::post('/projects/{project}/milestones', [MilestoneController::class, 'store'])->name('projects.milestones.store');
    Route::get('/projects/{project}/milestones/{milestone}/edit', [MilestoneController::class, 'edit'])->name('projects.milestones.edit');
    Route::patch('/projects/{project}/milestones/{milestone}', [MilestoneController::class, 'update'])->name('projects.milestones.update');
    Route::patch('/projects/{project}/milestones/{milestone}/retire', [MilestoneController::class, 'retire'])->name('projects.milestones.retire');
    Route::patch('/projects/{project}/milestones/{milestone}/restore', [MilestoneController::class, 'restore'])->name('projects.milestones.restore');

    Route::post('/projects/{project}/observations', [ObservationController::class, 'store'])->name('projects.observations.store');
    Route::get('/projects/{project}/observations/{observation}/edit', [ObservationController::class, 'edit'])->name('projects.observations.edit');
    Route::patch('/projects/{project}/observations/{observation}', [ObservationController::class, 'update'])->name('projects.observations.update');
    Route::patch('/projects/{project}/observations/{observation}/verify', [ObservationController::class, 'verify'])->name('projects.observations.verify');
    Route::post('/projects/{project}/observations/{observation}/corrections', [ObservationController::class, 'storeCorrection'])->name('projects.observations.corrections.store');
    Route::patch('/projects/{project}/observations/{observation}/corrections/{correction}', [ObservationController::class, 'reviewCorrection'])->name('projects.observations.corrections.review');

    Route::get('/projects/{project}/test-reports/create', [TestReportController::class, 'create'])->name('projects.test-reports.create');
    Route::post('/projects/{project}/test-reports', [TestReportController::class, 'store'])->name('projects.test-reports.store');
    Route::get('/projects/{project}/test-reports/{testReport}/edit', [TestReportController::class, 'edit'])->name('projects.test-reports.edit');
    Route::patch('/projects/{project}/test-reports/{testReport}', [TestReportController::class, 'update'])->name('projects.test-reports.update');
    Route::post('/projects/{project}/test-reports/{testReport}/confirm', [TestReportController::class, 'confirm'])->name('projects.test-reports.confirm');

    Route::post('/projects/{project}/observations/{observation}/attachments', [AttachmentController::class, 'storeObservationFile'])->name('projects.observations.attachments.store');
    Route::post('/projects/{project}/test-reports/{testReport}/attachments', [AttachmentController::class, 'storeTestReportFile'])->name('projects.test-reports.attachments.store');
    Route::get('/projects/{project}/attachments/{attachment}', [AttachmentController::class, 'download'])->name('projects.attachments.download');
    Route::patch('/projects/{project}/attachments/{attachment}/review', [AttachmentController::class, 'review'])->name('projects.attachments.review');
    Route::delete('/projects/{project}/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('projects.attachments.destroy');

    Route::post('/projects/{project}/team/invite', [TeamController::class, 'invite'])->name('projects.team.invite');
    Route::patch('/projects/{project}/team/members/{member}/revoke', [TeamController::class, 'revokeMember'])->name('projects.team.members.revoke');
    Route::patch('/projects/{project}/team/invitations/{invitation}/revoke', [TeamController::class, 'revokeInvitation'])->name('projects.team.invitations.revoke');
    Route::post('/projects/{project}/team/invitations/{invitation}/accept', [TeamController::class, 'accept'])->name('projects.team.accept');
    Route::post('/projects/{project}/team/invitations/{invitation}/decline', [TeamController::class, 'decline'])->name('projects.team.decline');

    Route::post('/projects/{project}/discussion', [DiscussionController::class, 'store'])
        ->middleware('throttle:20,1')->name('projects.discussion.store');
    Route::post('/projects/{project}/discussion/{post}/replies', [DiscussionController::class, 'reply'])
        ->middleware('throttle:30,1')->name('projects.discussion.replies.store');
    Route::get('/projects/{project}/discussion/{post}/edit', [DiscussionController::class, 'edit'])->name('projects.discussion.edit');
    Route::patch('/projects/{project}/discussion/{post}', [DiscussionController::class, 'update'])->name('projects.discussion.update');
    Route::delete('/projects/{project}/discussion/{post}', [DiscussionController::class, 'destroy'])->name('projects.discussion.destroy');
    Route::patch('/projects/{project}/discussion/{post}/moderate', [DiscussionController::class, 'moderate'])->name('projects.discussion.moderate');
    Route::patch('/projects/{project}/discussion-toggle', [DiscussionController::class, 'toggle'])->name('projects.discussion.toggle');
    Route::post('/projects/{project}/discussion/{post}/mute', [DiscussionController::class, 'mute'])->name('projects.discussion.mute');
    Route::delete('/projects/{project}/discussion/{post}/mute', [DiscussionController::class, 'unmute'])->name('projects.discussion.unmute');

    Route::post('/projects/{project}/follow', [FollowController::class, 'followProject'])->name('projects.follow');
    Route::delete('/projects/{project}/follow', [FollowController::class, 'unfollowProject'])->name('projects.unfollow');
    Route::post('/projects/{project}/discussion/{post}/follow', [FollowController::class, 'followThread'])->name('projects.discussion.follow');
    Route::delete('/projects/{project}/discussion/{post}/follow', [FollowController::class, 'unfollowThread'])->name('projects.discussion.unfollow');

    Route::get('/projects/{project}/discussion/{post}/promote', [PromotionController::class, 'create'])->name('projects.discussion.promote');
    Route::post('/projects/{project}/discussion/{post}/promote', [PromotionController::class, 'store'])->name('projects.discussion.promote.store');

    Route::post('/projects/{project}/discussion/{post}/report', [ModerationController::class, 'storePost'])->name('projects.discussion.report');
    Route::get('/projects/{project}/moderation', [ModerationController::class, 'index'])->name('projects.moderation.index');
    Route::patch('/projects/{project}/moderation/{report}', [ModerationController::class, 'review'])->name('projects.moderation.review');

    Route::get('/projects/{project}/chat/history', [ChatController::class, 'history'])->name('projects.chat.history');
    Route::post('/projects/{project}/chat/messages', [ChatController::class, 'send'])
        ->middleware('throttle:30,1')->name('projects.chat.send');
    Route::delete('/projects/{project}/chat/{message}', [ChatController::class, 'destroy'])->name('projects.chat.destroy');
    Route::post('/projects/{project}/chat/read', [ChatController::class, 'read'])->name('projects.chat.read');
    Route::post('/projects/{project}/chat/{message}/report', [ModerationController::class, 'storeMessage'])->name('projects.chat.report');

    Route::post('/lounge/messages', [ChatController::class, 'loungeSend'])
        ->middleware('throttle:30,1')->name('lounge.send');
    Route::delete('/lounge/chat/{message}', [ChatController::class, 'destroyLoungeMessage'])->name('lounge.destroy');
    Route::post('/lounge/read', [ChatController::class, 'loungeRead'])->name('lounge.read');
    Route::post('/lounge/chat/{message}/report', [ModerationController::class, 'storeLoungeMessage'])->name('lounge.report');

    Route::get('/chat/unread', [ChatController::class, 'unread'])->name('chat.unread');
});

Route::get('/projects/{project}/team', [TeamController::class, 'index'])->name('projects.team.index');

Route::get('/projects/{project}/discussion', [DiscussionController::class, 'index'])->name('projects.discussion.index');
Route::get('/projects/{project}/discussion/{post}', [DiscussionController::class, 'show'])->name('projects.discussion.show');
Route::get('/projects/{project}/updates', [DiscussionController::class, 'updatesIndex'])->name('projects.updates.index');
Route::get('/projects/{project}/updates/{post}', [DiscussionController::class, 'show'])->name('projects.updates.show');

Route::get('/users/{user}', [PublicProfileController::class, 'show'])->name('users.show');

Route::get('/lounge', [ChatController::class, 'lounge'])->name('lounge');
Route::get('/lounge/history', [ChatController::class, 'loungeHistory'])->name('lounge.history');
Route::view('/online', 'online')->name('online');
Route::get('/projects/{project}/chat', [ChatController::class, 'show'])->name('projects.chat.show');

Route::view('/tasks', 'tasks.index')->name('tasks.index');

Route::get('/projects/{project}/export/{format}', [ExportController::class, 'show'])->name('projects.export');

Route::get('/projects/{project}/test-reports/{testReport}', [TestReportController::class, 'show'])->name('projects.test-reports.show');

Route::get('/projects/{project}/tasks/{task}', [TaskController::class, 'show'])->name('projects.tasks.show');

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/public', [ProfileController::class, 'updatePublicProfile'])->name('profile.public.update');
    Route::patch('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/profile/avatar', [AvatarController::class, 'store'])->name('avatars.store');
    Route::delete('/profile/avatar', [AvatarController::class, 'destroy'])->name('avatars.destroy');
});

Route::get('/avatars/{user}', [AvatarController::class, 'show'])->name('avatars.show');
Route::get('/u/{handle}', [PublicProfileController::class, 'byHandle'])
    ->where('handle', '[A-Za-z0-9_-]+')->name('users.handle');

require __DIR__.'/auth.php';
