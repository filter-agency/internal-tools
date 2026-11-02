<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TellPaulSuggestion extends Mailable
{
    /** @param array{title: string, proposal: string, reason: string, benefits: string, exploration: string} $suggestion */
    public function __construct(
        public array $suggestion,
        public string $submitterName,
        public string $submitterEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Idea Box: '.$this->suggestion['title'],
            replyTo: [new Address($this->submitterEmail, $this->submitterName)],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'emails.tell-paul');
    }
}
