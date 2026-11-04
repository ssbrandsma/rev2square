<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Base notification: every product notification is queueable and
 * delivered to the database (surfaced in the inbox). Payloads live in
 * each child's toArray().
 */
abstract class DatabaseNotification extends Notification
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }
}
