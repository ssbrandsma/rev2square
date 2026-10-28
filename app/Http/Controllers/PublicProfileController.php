<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserHandle;
use App\Services\Profile\MemberProfile;
use Illuminate\View\View;

class PublicProfileController extends Controller
{
    public function show(User $user, MemberProfile $profiles): View
    {
        return $this->render($user, $profiles);
    }

    /**
     * Handle route: resolves current handles and retired ones, so a
     * renamed member's old links keep working.
     */
    public function byHandle(string $handle, MemberProfile $profiles): View
    {
        $user = User::where('handle', strtolower($handle))->first()
            ?? UserHandle::where('handle', strtolower($handle))->first()?->user;

        abort_unless($user !== null, 404);

        return $this->render($user, $profiles);
    }

    private function render(User $user, MemberProfile $profiles): View
    {
        return view('users.show', [
            'profileUser' => $user,
            'sections' => $profiles->publicSections($user),
            'isSelf' => auth()->id() === $user->id,
        ]);
    }
}
