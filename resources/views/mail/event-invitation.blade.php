<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Event invitation</title>
</head>
<body style="margin:0;background:#fffaf7;color:#25170f;font-family:Arial,sans-serif;">
    <div style="max-width:600px;margin:0 auto;padding:32px 20px;">
        <div style="background:#ffffff;border:1px solid #ffdece;border-radius:16px;padding:28px;text-align:center;">
            <div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#f6671e;">You're invited</div>
            <h1 style="margin:10px 0 6px;font-size:26px;">{{ $event->title }}</h1>
            <p style="margin:0 0 22px;color:#6f625b;line-height:1.6;">Register using the button or scan the QR code below.</p>

            <a href="{{ $registrationUrl }}" style="display:inline-block;border-radius:10px;background:#f6671e;padding:12px 22px;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;">Register Now</a>

            <div style="margin:24px 0 12px;color:#6f625b;font-size:13px;font-weight:700;text-transform:uppercase;">Or scan to register</div>
            <img src="{{ $message->embedData($qrPng, 'event-registration-qr-inline.png', 'image/png') }}" width="260" height="260" alt="Event registration QR code" style="display:block;margin:0 auto 16px;max-width:100%;height:auto;">

            <div style="margin-top:24px;text-align:left;border-top:1px solid #ffdece;padding-top:18px;color:#6f625b;font-size:14px;line-height:1.6;">
                <div><strong style="color:#25170f;">Date:</strong> {{ $event->starts_at->copy()->setTimezone($event->timezone)->format('M j, Y g:i A') }}</div>
                <div><strong style="color:#25170f;">Venue:</strong> {{ $event->venue ?: 'Online event' }}</div>
            </div>

            <p style="margin:20px 0 6px;color:#6f625b;font-size:12px;">If the button does not work, open this registration link:</p>
            <a href="{{ $registrationUrl }}" style="color:#dc4f0a;font-size:12px;word-break:break-all;">{{ $registrationUrl }}</a>
        </div>
    </div>
</body>
</html>
