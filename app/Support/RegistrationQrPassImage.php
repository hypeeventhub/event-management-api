<?php

namespace App\Support;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

class RegistrationQrPassImage
{
    public static function qrPng(string $registrationCode): string
    {
        return Builder::create()
            ->writer(new PngWriter)
            ->data($registrationCode)
            ->encoding(new Encoding('ISO-8859-1'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::Medium)
            ->size(300)
            ->margin(12)
            ->roundBlockSizeMode(RoundBlockSizeMode::Margin)
            ->build()
            ->getString();
    }
}
