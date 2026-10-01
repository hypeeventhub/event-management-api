<?php

namespace App\Mail;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EventInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Event $event,
        public string $registrationUrl,
        public string $qrPng,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invitation: '.$this->event->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.event-invitation',
        );
    }
}
