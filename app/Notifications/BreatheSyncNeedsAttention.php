<?php

namespace App\Notifications;

use App\Notifications\Concerns\ThrottlesQueuedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class BreatheSyncNeedsAttention extends Notification implements ShouldQueue
{
    use Queueable, ThrottlesQueuedMail;

    /**
     * @param  list<string>  $unmatched
     * @param  list<string>  $problems
     */
    public function __construct(
        public readonly ?string $failure,
        public readonly array $unmatched,
        public readonly array $problems,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->failure !== null
                ? 'Action needed: Internal Tools Breathe HR sync failed'
                : 'Check needed: Internal Tools Breathe HR sync')
            ->view('emails.integrations.breathe-sync-needs-attention', [
                'failure' => $this->failure,
                'unmatched' => $this->unmatched,
                'problems' => $this->problems,
                'usersUrl' => route('admin.users'),
            ]);
    }
}
