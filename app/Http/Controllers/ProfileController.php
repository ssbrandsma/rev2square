<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Update presence and notification preferences. The profile prompt
     * expands these settings; the fields stay stable.
     */
    public function updatePreferences(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'show_presence' => ['nullable', 'boolean'],
            'notify_discussion_replies' => ['nullable', 'boolean'],
            'notify_mentions' => ['nullable', 'boolean'],
            'notify_followed_posts' => ['nullable', 'boolean'],
            'notify_project_updates' => ['nullable', 'boolean'],
        ]);

        $request->user()->forceFill([
            'show_presence' => (bool) ($data['show_presence'] ?? false),
            'notification_preferences' => [
                'discussion_replies' => (bool) ($data['notify_discussion_replies'] ?? false),
                'mentions' => (bool) ($data['notify_mentions'] ?? false),
                'followed_posts' => (bool) ($data['notify_followed_posts'] ?? false),
                'project_updates' => (bool) ($data['notify_project_updates'] ?? false),
            ],
        ])->save();

        return Redirect::route('profile.edit')->with('status', 'preferences-updated');
    }

    /**
     * Handles that can never belong to a member: route/system words and
     * role-sounding names that would imply authority.
     *
     * @return list<string>
     */
    public const RESERVED_HANDLES = [
        'admin', 'administrator', 'root', 'support', 'help', 'system',
        'rev2square', 'rev2', 'moderator', 'mod', 'official', 'team',
        'api', 'app', 'assets', 'storage', 'avatars', 'broadcasting',
        'dashboard', 'inbox', 'login', 'logout', 'register', 'profile',
        'projects', 'project', 'tasks', 'task', 'users', 'user', 'u',
        'lounge', 'online', 'explore', 'about', 'methodology', 'up',
    ];

    /**
     * Update the member's public profile fields. Auth identity (email,
     * password) lives in separate forms; this only touches what the
     * public profile shows.
     */
    public function updatePublicProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (is_string($request->input('handle'))) {
            $request->merge(['handle' => strtolower(trim($request->input('handle')))]);
        }

        $data = $request->validate([
            'handle' => [
                'nullable', 'string', 'max:30',
                'regex:/^[a-z0-9](?:[a-z0-9_-]{1,28}[a-z0-9])?$/',
                Rule::notIn(self::RESERVED_HANDLES),
                Rule::unique('users', 'handle')->ignore($user->id),
                Rule::unique('user_handles', 'handle')->whereNot('user_id', $user->id),
            ],
            'bio' => ['nullable', 'string', 'max:2000'],
            'skills' => ['nullable', 'string', 'max:1000'],
            'links' => ['nullable', 'array', 'max:5'],
            'links.*.label' => ['nullable', 'string', 'max:80'],
            'links.*.url' => ['nullable', 'url:http,https', 'max:500'],
            'availability' => ['nullable', 'string', 'max:255'],
            'hardware_interests' => ['nullable', 'string', 'max:2000'],
        ]);

        $handle = isset($data['handle']) && trim($data['handle']) !== ''
            ? strtolower(trim($data['handle']))
            : null;

        // A freed handle stays reserved to this user: old profile links
        // keep resolving and nobody else can claim the name.
        if ($handle !== $user->handle && $user->handle !== null) {
            $user->previousHandles()->firstOrCreate(['handle' => $user->handle]);
        }

        // Reclaiming an old handle removes the now-redundant history row.
        if ($handle !== null) {
            $user->previousHandles()->where('handle', $handle)->delete();
        }

        $skills = collect(explode(',', (string) ($data['skills'] ?? '')))
            ->map(fn (string $skill): string => trim($skill))
            ->filter()
            ->take(20)
            ->values()
            ->all();

        $links = collect($data['links'] ?? [])
            ->map(fn (array $link): array => [
                'label' => trim((string) ($link['label'] ?? '')),
                'url' => trim((string) ($link['url'] ?? '')),
            ])
            ->filter(fn (array $link): bool => $link['url'] !== '')
            ->values()
            ->all();

        $user->forceFill([
            'handle' => $handle,
            'bio' => isset($data['bio']) && trim($data['bio']) !== '' ? trim($data['bio']) : null,
            'skills' => $skills === [] ? null : $skills,
            'links' => $links === [] ? null : $links,
            'availability' => isset($data['availability']) && trim($data['availability']) !== '' ? trim($data['availability']) : null,
            'hardware_interests' => isset($data['hardware_interests']) && trim($data['hardware_interests']) !== '' ? trim($data['hardware_interests']) : null,
        ])->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
