<?php

namespace App\Livewire;

use App\Mail\TellPaulSuggestion;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class TellPaul extends Component
{
    public string $title = '';

    public string $proposal = '';

    public string $reason = '';

    public string $benefits = '';

    public string $exploration = '';

    public function submit(): void
    {
        $user = auth()->user();
        abort_if($user === null, 403);

        $this->validate([
            'title' => 'required|string|max:200',
            'proposal' => 'required|string|max:10000',
            'reason' => 'required|string|max:10000',
            'benefits' => 'required|string|max:10000',
            'exploration' => 'required|string|max:10000',
        ]);

        $key = 'tell-paul:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('submit', 'Thanks for sharing your ideas. Please wait a few minutes before sending another.');

            return;
        }

        $recipient = config('tell-paul.recipient_email');
        if (! is_string($recipient) || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->addError('submit', 'Email delivery is not configured. Please try again later.');

            return;
        }

        try {
            // Send immediately: suggestion content is never stored in a database queue.
            Mail::mailer(config('tell-paul.mailer'))->to($recipient)->send(new TellPaulSuggestion(
                suggestion: $this->only(['title', 'proposal', 'reason', 'benefits', 'exploration']),
                submitterName: $user->name,
                submitterEmail: $user->email,
            ));
        } catch (\Throwable) {
            // Do not log the exception: mail transport errors can include message content.
            $this->addError('submit', 'Your email could not be sent. Your answers are still here — please try again.');

            return;
        }
        RateLimiter::hit($key, 600);
        $this->reset();
        session()->flash('suggestion-sent', 'Thanks for sharing your idea. Your idea has been emailed to us.');
    }

    public function render(): View
    {
        return view('livewire.tell-paul');
    }
}
