<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GlobalRole;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditEvent;
use App\Models\AiUsageEntry;
use App\Models\User;
use App\Services\Admin\AdminUserService;
use App\Services\Admin\AiBudgets;
use App\Services\Admin\LastAdminException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('admin');

        $users = User::query()
            ->when($request->string('q')->toString() !== '', function ($query) use ($request): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $request->string('q')->toString()).'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($request->string('role')->toString() !== '', fn ($q) => $q->where('global_role', $request->string('role')->toString()))
            ->when($request->string('status')->toString() !== '', fn ($q) => $q->where('account_status', $request->string('status')->toString()))
            ->when($request->string('from')->toString() !== '', fn ($q) => $q->whereDate('created_at', '>=', $request->string('from')->toString()))
            ->when($request->string('to')->toString() !== '', fn ($q) => $q->whereDate('created_at', '<=', $request->string('to')->toString()))
            ->withCount(['ownedProjects', 'projectMemberships'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.index', ['users' => $users, 'filters' => $request->only(['q', 'role', 'status', 'from', 'to'])]);
    }

    public function show(User $user): View
    {
        Gate::authorize('admin');

        $user->loadCount(['ownedProjects', 'projectMemberships', 'createdProjects']);

        $lastSession = null;
        try {
            if (Schema::hasTable('sessions')) {
                $lastSession = DB::table('sessions')->where('user_id', $user->id)->max('last_activity');
            }
        } catch (\Throwable) {
            $lastSession = null;
        }

        $usage = AiUsageEntry::where('user_id', $user->id)
            ->selectRaw('COUNT(*) AS requests, COALESCE(SUM(input_tokens),0)+COALESCE(SUM(output_tokens),0) AS tokens')
            ->first();

        $projectsNeedingAttention = $user->isSuspended()
            ? $user->ownedProjects()->orderBy('title')->pluck('title', 'id')->all()
            : [];

        return view('admin.users.show', [
            'user' => $user,
            'lastActivity' => $lastSession !== null ? now()->createFromTimestamp($lastSession) : null,
            'todayUsage' => AiBudgets::todayUsage($user->id),
            'totalUsage' => ['requests' => (int) ($usage->requests ?? 0), 'tokens' => (int) ($usage->tokens ?? 0)],
            'projectsNeedingAttention' => $projectsNeedingAttention,
            'recentAudits' => AdminAuditEvent::where('target_type', $user->getMorphClass())
                ->where('target_id', $user->id)
                ->latest('id')
                ->take(10)
                ->get(),
        ]);
    }

    public function edit(User $user): View
    {
        Gate::authorize('admin');

        return view('admin.users.edit', ['user' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('admin');

        $data = $request->validate([
            'global_role' => ['required', Rule::enum(GlobalRole::class)],
            'ai_enabled' => ['required', 'boolean'],
            'ai_daily_request_limit' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'ai_daily_token_limit' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'ai_unlimited' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            AdminUserService::updateAccount($request->user(), $user->id, [
                'global_role' => $data['global_role'],
                'ai_enabled' => (bool) $data['ai_enabled'],
                'ai_daily_request_limit' => $data['ai_daily_request_limit'] ?? null,
                'ai_daily_token_limit' => $data['ai_daily_token_limit'] ?? null,
                'ai_unlimited' => (bool) $data['ai_unlimited'],
            ], $data['reason']);
        } catch (LastAdminException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.users.show', $user)->with('status', 'Account updated.');
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('admin');

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        try {
            AdminUserService::suspend($request->user(), $user->id, $data['reason']);
        } catch (LastAdminException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.users.show', $user)->with('status', 'Account suspended. Sessions revoked; projects keep their attribution.');
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('admin');

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        AdminUserService::reactivate($request->user(), $user->id, $data['reason']);

        return redirect()->route('admin.users.show', $user)->with('status', 'Account reactivated with its previous role and limits.');
    }
}
