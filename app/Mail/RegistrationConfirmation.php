<?php

namespace App\Mail;

use App\Models\Registration;
use App\Support\RegistrationQrPassImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class RegistrationConfirmation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    private ?string $qrPngCache = null;

    public function __construct(public Registration $registration) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your QR pass: '.$this->registration->event->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.registration-confirmation',
            with: [
                'qrPng' => $this->qrPng(),
                'qrDownloadUrl' => $this->qrDownloadUrl(),
            ],
        );
    }

    public function qrPng(): string
    {
        return $this->qrPngCache ??= RegistrationQrPassImage::qrPng($this->registration->registration_code);
    }

    public function qrDownloadUrl(): string
    {
        return URL::temporarySignedRoute(
            'registrations.qr-download',
            now()->addDays(30),
            ['registration' => $this->registration->getKey()],
        );
    }
}
