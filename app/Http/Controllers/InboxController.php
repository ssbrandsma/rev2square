<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class InboxController extends Controller
{
    public function index(): View
    {
        $notifications = auth()->user()->notifications()->latest()->paginate(30);

        // Unpublishing a project removes access to its notification excerpts:
        // rows pointing at projects the viewer can no longer see are hidden.
        $projectIds = $notifications->getCollection()
            ->map(fn ($notification) => $notification->data['project_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($projectIds->isNotEmpty()) {
            $visibleIds = Project::whereIn('id', $projectIds)->get()
                ->filter(fn (Project $project): bool => auth()->user()->can('view', $project))
                ->map->id;

            $notifications->setCollection($notifications->getCollection()->filter(
                fn ($notification): bool => ! isset($notification->data['project_id'])
                    || $visibleIds->contains($notification->data['project_id'])
            ));
        }

        return view('inbox.index', ['notifications' => $notifications]);
    }

    public function readAll(): RedirectResponse
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('status', 'Inbox marked as read.');
    }

    public function read(string $id): RedirectResponse
    {
        $notification = auth()->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $url = $notification->data['url'] ?? '';

        // Notification targets are server-generated, but never redirect
        // off-site even if a stored payload is tampered with.
        $appUrl = rtrim((string) config('app.url'), '/');

        if (! is_string($url) || (! str_starts_with($url, '/') && ! str_starts_with($url, $appUrl.'/'))) {
            $url = route('inbox.index');
        }

        return redirect($url);
    }
}
