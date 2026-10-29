<?php

use App\Http\Controllers\Admin\AuditController as AdminAuditController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\ModerationController as AdminModerationController;
use App\Http\Controllers\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Admin\UsageController as AdminUsageController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\ArchitectureController;
use App\Http\Controllers\ArtifactController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\BinaryAnalysisController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscussionController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MilestoneController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\MyUsageController;
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
    $featured = Project::featurable()->with(['devices', 'tasks', 'cover'])->latest()->take(2)->get();

    return view('welcome', ['featured' => $featured]);
})->name('home');

Route::view('/about', 'about')->name('about');
Route::view('/suspended', 'suspended')->name('suspended');

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
Route::get('/projects/{project}/evidence/{source}', [EvidenceController::class, 'showSource'])
    ->middleware('auth')->name('projects.evidence.show');
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
Route::post('/projects/{project}/agent/investigate', [AgentController::class, 'investigate'])
    ->middleware('auth')->name('projects.agent.investigate');
Route::post('/projects/{project}/agent/{conversation}/withdraw-consent', [AgentController::class, 'withdrawConsent'])
    ->middleware('auth')->name('projects.agent.withdraw-consent');
Route::get('/projects/{project}/artifacts', [ArtifactController::class, 'index'])
    ->middleware('auth')->name('projects.artifacts.index');
Route::post('/projects/{project}/artifacts', [ArtifactController::class, 'store'])
    ->middleware('auth')->name('projects.artifacts.store');
Route::get('/projects/{project}/artifacts/{artifact}', [ArtifactController::class, 'show'])
    ->middleware('auth')->name('projects.artifacts.show');
Route::post('/projects/{project}/artifacts/{artifact}/retry', [ArtifactController::class, 'retry'])
    ->middleware('auth')->name('projects.artifacts.retry');
Route::delete('/projects/{project}/artifacts/{artifact}', [ArtifactController::class, 'destroy'])
    ->middleware('auth')->name('projects.artifacts.destroy');
Route::post('/projects/{project}/artifacts/{artifact}/analyses', [BinaryAnalysisController::class, 'store'])
    ->middleware('auth')->name('projects.artifacts.analyses.store');
Route::get('/projects/{project}/artifacts/{artifact}/analyses/{analysis}', [BinaryAnalysisController::class, 'show'])
    ->middleware('auth')->name('projects.artifacts.analyses.show');
Route::post('/projects/{project}/artifacts/{artifact}/analyses/{analysis}/cancel', [BinaryAnalysisController::class, 'cancel'])
    ->middleware('auth')->name('projects.artifacts.analyses.cancel');
Route::post('/projects/{project}/artifacts/{artifact}/analyses/{analysis}/retry', [BinaryAnalysisController::class, 'retry'])
    ->middleware('auth')->name('projects.artifacts.analyses.retry');

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

Route::get('/my/ai-usage', [MyUsageController::class, 'show'])
    ->middleware('auth')->name('my-usage.show');

Route::post('/projects/{project}/media/images', [MediaController::class, 'storeImage'])
    ->middleware('auth')->name('projects.media.store-image');
Route::post('/projects/{project}/media/files', [MediaController::class, 'storeFile'])
    ->middleware('auth')->name('projects.media.store-file');
Route::patch('/projects/{project}/media/{media}', [MediaController::class, 'update'])
    ->middleware('auth')->name('projects.media.update');
Route::post('/projects/{project}/media/reorder', [MediaController::class, 'reorder'])
    ->middleware('auth')->name('projects.media.reorder');
Route::post('/projects/{project}/media/{media}/cover', [MediaController::class, 'setCover'])
    ->middleware('auth')->name('projects.media.cover');
Route::post('/projects/{project}/media/{media}/retry', [MediaController::class, 'retry'])
    ->middleware('auth')->name('projects.media.retry');
Route::delete('/projects/{project}/media/cover', [MediaController::class, 'removeCover'])
    ->middleware('auth')->name('projects.media.cover.destroy');
Route::delete('/projects/{project}/media/{media}', [MediaController::class, 'destroy'])
    ->middleware('auth')->name('projects.media.destroy');
Route::get('/projects/{project}/media/{media}', [MediaController::class, 'show'])
    ->name('projects.media.show');
Route::get('/projects/{project}/media/{media}/download', [MediaController::class, 'download'])
    ->name('projects.media.download');
Route::patch('/projects/{project}/story', [ProjectController::class, 'updateStory'])
    ->middleware('auth')->name('projects.story.update');

// Admin area: every route re-authorizes server-side (hiding navigation
// never secures a route). Consequential mutations additionally require
// a recent password confirmation. No MFA facility exists in this
// application (documented in the delivery report).
Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
    Route::get('/users/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
    Route::patch('/users/{user}', [AdminUserController::class, 'update'])->middleware('password.confirm')->name('users.update');
    Route::post('/users/{user}/suspend', [AdminUserController::class, 'suspend'])->middleware('password.confirm')->name('users.suspend');
    Route::post('/users/{user}/reactivate', [AdminUserController::class, 'reactivate'])->middleware('password.confirm')->name('users.reactivate');
    Route::get('/settings', [AdminSettingController::class, 'index'])->name('settings.index');
    Route::patch('/settings', [AdminSettingController::class, 'update'])->middleware('password.confirm')->name('settings.update');
    Route::get('/settings/secrets', [AdminSettingController::class, 'secrets'])->name('settings.secrets');
    Route::patch('/settings/secrets', [AdminSettingController::class, 'updateSecret'])->middleware('password.confirm')->name('settings.secrets.update');
    Route::delete('/settings/secrets', [AdminSettingController::class, 'destroySecret'])->middleware('password.confirm')->name('settings.secrets.destroy');
    Route::post('/settings/test-connection', [AdminSettingController::class, 'testConnection'])->middleware('password.confirm')->name('settings.test');
    Route::get('/usage', [AdminUsageController::class, 'index'])->name('usage.index');
    Route::get('/usage/export', [AdminUsageController::class, 'export'])->name('usage.export');
    Route::get('/usage/users/{user}', [AdminUsageController::class, 'user'])->name('usage.user');
    Route::get('/audit', [AdminAuditController::class, 'index'])->name('audit.index');
});

Route::middleware(['auth', 'can:moderate'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/moderation', [AdminModerationController::class, 'index'])->name('moderation.index');
    Route::post('/moderation/{report}', [AdminModerationController::class, 'review'])->name('moderation.review');
});

require __DIR__.'/auth.php';
