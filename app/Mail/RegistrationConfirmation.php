<?php

namespace App\Mail;

use App\Models\Registration;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

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
            with: ['qrPng' => $this->qrPng()],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->qrPng(), 'attendee-qr-pass.png')
                ->withMime('image/png'),
        ];
    }

    public function qrPng(): string
    {
        return $this->qrPngCache ??= Builder::create()
            ->writer(new PngWriter)
            ->data($this->registration->registration_code)
            ->encoding(new Encoding('ISO-8859-1'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
            ->size(300)
            ->margin(12)
            ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
            ->build()
            ->getString();
    }
}
