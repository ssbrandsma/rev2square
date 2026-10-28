<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Profile\AvatarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AvatarController extends Controller
{
    public function show(User $user): StreamedResponse
    {
        abort_unless($user->avatar_path !== null && Storage::disk('local')->exists($user->avatar_path), 404);

        return Storage::disk('local')->response($user->avatar_path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function store(Request $request, AvatarService $avatars): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'max:2048', 'mimetypes:image/jpeg,image/png,image/webp,image/gif'],
        ]);

        try {
            $avatars->store($request->user(), $request->file('avatar'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['avatar' => $e->getMessage()]);
        }

        return back()->with('status', 'Avatar updated.');
    }

    public function destroy(Request $request, AvatarService $avatars): RedirectResponse
    {
        // Routes are auth-only and always act on the signed-in user: there
        // is no target-user parameter to tamper with.
        $avatars->delete($request->user());

        return back()->with('status', 'Avatar removed; your initials show instead.');
    }
}
