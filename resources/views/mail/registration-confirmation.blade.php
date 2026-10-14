<!DOCTYPE html>
<html lang="en">
@php
    $qrDownloadUrl ??= \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'registrations.qr-download',
        now()->addDays(30),
        ['registration' => $registration->getKey()],
    );
@endphp
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your QR pass</title>
</head>
<body style="margin:0;background:#fffaf7;color:#25170f;font-family:Arial,sans-serif;">
    <div style="max-width:600px;margin:0 auto;padding:32px 20px;">
        <div style="background:#ffffff;border:1px solid #ffdece;border-radius:16px;padding:28px;text-align:center;">
            <div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#f6671e;">Registration confirmed</div>
            <h1 style="margin:10px 0 6px;font-size:26px;">{{ $registration->event->title }}</h1>
            <p style="margin:0 0 20px;color:#6f625b;">Hello {{ $registration->attendee->first_name }} {{ $registration->attendee->last_name }}, present this QR pass when you arrive.</p>
            <p style="margin:0 0 20px;color:#6f625b;">Please download the QR and note the registration code for event purposes</p>

            <a href="{{ $qrDownloadUrl }}" style="display:block;color:#a8460d;text-decoration:none;">
                <img src="{{ $message->embedData($qrPng, 'attendee-qr-pass-inline.png', 'image/png') }}" width="260" height="260" alt="Attendee registration QR code" style="display:block;margin:0 auto 16px;max-width:100%;height:auto;">
            </a>

            <table role="presentation" align="center" width="190" cellpadding="0" cellspacing="0" style="width:100%;max-width:190px;margin:28px auto;">
                <tr>
                    <td align="center">
                        <div style="display:block;border-radius:10px;background:#fff4ee;padding:10px;color:#f6671e;font-family:monospace;font-size:18px;font-weight:700;text-align:center;overflow-wrap:anywhere;">
                            {{ $registration->registration_code }}
                        </div>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:0 0 12px;">
                        <a href="{{ $qrDownloadUrl }}" style="display:block;border-radius:8px;background:#f6671e;padding:12px 10px;color:#ffffff;font-size:14px;font-weight:700;text-align:center;text-decoration:none;">Download QR pass (PDF)</a>
                    </td>
                </tr>
            </table>

            <div style="margin-top:24px;text-align:left;border-top:1px solid #ffdece;padding-top:18px;color:#6f625b;font-size:14px;line-height:1.6;">
                <div><strong style="color:#25170f;">Date:</strong> {{ $registration->event->starts_at->copy()->setTimezone($registration->event->timezone)->format('M j, Y g:i A') }}</div>
                <div><strong style="color:#25170f;">Venue:</strong> {{ $registration->event->venue ?: 'Online event' }}</div>
            </div>

        </div>
    </div>
</body>
</html>
