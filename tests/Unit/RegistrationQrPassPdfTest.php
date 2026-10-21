<?php

use App\Support\RegistrationQrPassImage;
use App\Support\RegistrationQrPassPdf;

test('a long qr description wraps onto multiple lines and expands the pdf page', function () {
    $description = str_repeat('Bring your QR pass to the welcome desk. ', 20);
    $pdf = RegistrationQrPassPdf::generate(
        RegistrationQrPassImage::qrPng('REG-TEST'),
        'Annual Gala',
        'Jamie Rivera',
        'REG-TEST',
        'Oct 28, 2026 7:00 PM',
        'Skyline Lounge',
        $description,
    );

    preg_match('/\/MediaBox \[0 0 595 (\d+)\]/', $pdf, $pageSize);

    expect((int) ($pageSize[1] ?? 0))->toBeGreaterThan(580)
        ->and(substr_count($pdf, 'Bring your QR pass'))->toBeGreaterThan(1)
        ->and($pdf)->toContain('REG-TEST');
});
