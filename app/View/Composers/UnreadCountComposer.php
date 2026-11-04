<?php

namespace App\View\Composers;

use Illuminate\View\View;

/**
 * Shares the unread-notification count with the application layout and
 * the inbox page, so neither queries it inline. Guests get zero.
 */
class UnreadCountComposer
{
    public function compose(View $view): void
    {
        $user = auth()->user();

        $view->with('unreadCount', $user !== null ? $user->unreadNotifications()->count() : 0);
    }
}
