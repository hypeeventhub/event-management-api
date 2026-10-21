<?php

namespace App\Support;

use GdImage;
use RuntimeException;

class RegistrationQrPassPdf
{
    private const PAGE_WIDTH = 595;

    private const BASE_PAGE_HEIGHT = 580;

    public static function generate(
        string $qrPng,
        string $eventTitle,
        string $attendeeName,
        string $registrationCode,
        string $eventDate,
        string $venue,
        ?string $qrDescription = null,
    ): string {
        $qrImage = imagecreatefromstring($qrPng);

        if (! $qrImage instanceof GdImage) {
            throw new RuntimeException('The registration QR image could not be decoded for the PDF.');
        }

        $qrWidth = imagesx($qrImage);
        $qrHeight = imagesy($qrImage);
        $rawPixels = self::rgbPixels($qrImage);
        imagedestroy($qrImage);

        $compressedPixels = gzcompress($rawPixels, 9);

        if (! is_string($compressedPixels)) {
            throw new RuntimeException('The registration QR image could not be compressed for the PDF.');
        }

        $descriptionLines = self::descriptionLines($qrDescription ?? '');
        $extraHeight = count($descriptionLines) * 18;
        $pageHeight = self::BASE_PAGE_HEIGHT + $extraHeight;
        $content = self::pageContent($eventTitle, $attendeeName, $registrationCode, $eventDate, $venue, $descriptionLines, $extraHeight, $pageHeight);
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::PAGE_WIDTH.' '.$pageHeight.'] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> /XObject << /QR 6 0 R >> >> /Contents 7 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            6 => "<< /Type /XObject /Subtype /Image /Width {$qrWidth} /Height {$qrHeight} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length ".strlen($compressedPixels)." >>\nstream\n{$compressedPixels}\nendstream",
            7 => '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream",
        ];

        return self::buildPdf($objects);
    }

    private static function pageContent(
        string $eventTitle,
        string $attendeeName,
        string $registrationCode,
        string $eventDate,
        string $venue,
        array $descriptionLines,
        int $extraHeight,
        int $pageHeight,
    ): string {
        $ops = [];
        $ops[] = 'q 1 0.871 0.808 RG 0.8 w 0.5 0.5 '.(self::PAGE_WIDTH - 1).' '.($pageHeight - 1).' re S Q';
        $ops[] = self::centeredText('REGISTRATION CONFIRMED', 'F1', 12, 0.965, 0.404, 0.118, 541 + $extraHeight);
        $ops[] = self::centeredText($eventTitle, 'F2', 26, 0.145, 0.090, 0.059, 497 + $extraHeight, 539);
        $ops[] = self::centeredText('Hello '.$attendeeName.', present this QR pass when you arrive.', 'F1', 14, 0.435, 0.384, 0.357, 464 + $extraHeight, 539);

        foreach ($descriptionLines as $index => $line) {
            $ops[] = self::centeredText($line, 'F1', 12, 0.435, 0.384, 0.357, 446 + $extraHeight - ($index * 18), 515);
        }

        $qrSize = 236;
        $qrX = (self::PAGE_WIDTH - $qrSize) / 2;
        $ops[] = sprintf('q %d 0 0 %d %.2f 194 cm /QR Do Q', $qrSize, $qrSize, $qrX);

        $badgeWidth = min(539, max(150, self::textWidth($registrationCode, 14, true) + 32));
        $badgeX = (self::PAGE_WIDTH - $badgeWidth) / 2;
        $badgeY = 115;
        $badgeHeight = 48;
        $ops[] = self::roundedBadge($badgeX, $badgeY, $badgeWidth, $badgeHeight, 9);
        $ops[] = self::centeredText($registrationCode, 'F2', 14, 0.965, 0.404, 0.118, 132, $badgeWidth - 20);

        $ops[] = 'q 1 0.871 0.808 RG 0.8 w 28 89 m 567 89 l S Q';
        $ops[] = self::textAt('Date:', 'F2', 14, 0.145, 0.090, 0.059, 28, 63);
        $dateValueX = 28 + self::textWidth('Date:', 14, true) + 6;
        $ops[] = self::textAt($eventDate, 'F1', 14, 0.435, 0.384, 0.357, $dateValueX, 63);
        $ops[] = self::textAt('Venue:', 'F2', 14, 0.145, 0.090, 0.059, 28, 42);
        $venueValueX = 28 + self::textWidth('Venue:', 14, true) + 6;
        $ops[] = self::textAt($venue, 'F1', 14, 0.435, 0.384, 0.357, $venueValueX, 42);

        return implode("\n", $ops);
    }

    /** @return list<string> */
    private static function descriptionLines(string $description): array
    {
        $lines = [];

        foreach (preg_split('/\R/u', trim($description)) ?: [] as $paragraph) {
            if (trim($paragraph) === '') {
                if ($lines !== []) {
                    $lines[] = '';
                }

                continue;
            }

            $line = '';

            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $candidate = $line === '' ? $word : $line.' '.$word;

                if (self::textWidth(self::encodeText($candidate), 12, false) <= 515) {
                    $line = $candidate;

                    continue;
                }

                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }

                foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
                    if ($line !== '' && self::textWidth(self::encodeText($line.$character), 12, false) > 515) {
                        $lines[] = $line;
                        $line = '';
                    }

                    $line .= $character;
                }
            }

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private static function rgbPixels(GdImage $image): string
    {
        $pixels = '';
        $width = imagesx($image);
        $height = imagesy($image);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);
                $pixels .= chr(($color >> 16) & 0xFF).chr(($color >> 8) & 0xFF).chr($color & 0xFF);
            }
        }

        return $pixels;
    }

    private static function roundedBadge(float $x, float $y, float $width, float $height, float $radius): string
    {
        $right = $x + $width;
        $top = $y + $height;
        $curve = $radius * 0.55228475;

        return sprintf(
            'q 1 0.957 0.933 rg %.2f %.2f m %.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c h f Q',
            $x + $radius, $y,
            $right - $radius, $y,
            $right - $radius + $curve, $y, $right, $y + $radius - $curve, $right, $y + $radius,
            $right, $top - $radius,
            $right, $top - $radius + $curve, $right - $radius + $curve, $top, $right - $radius, $top,
            $x + $radius, $top,
            $x + $radius - $curve, $top, $x, $top - $radius + $curve, $x, $top - $radius,
            $x, $y + $radius,
            $x, $y + $radius - $curve, $x + $radius - $curve, $y, $x + $radius, $y,
        );
    }

    private static function centeredText(
        string $text,
        string $font,
        float $fontSize,
        float $red,
        float $green,
        float $blue,
        float $baseline,
        float $maxWidth = self::PAGE_WIDTH - 56,
    ): string {
        $encoded = self::encodeText($text);
        $widthAtSize = self::textWidth($encoded, $fontSize, $font === 'F2');

        if ($widthAtSize > $maxWidth) {
            $fontSize *= $maxWidth / $widthAtSize;
            $widthAtSize = $maxWidth;
        }

        return sprintf(
            'q %.3f %.3f %.3f rg BT /%s %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET Q',
            $red,
            $green,
            $blue,
            $font,
            $fontSize,
            (self::PAGE_WIDTH - $widthAtSize) / 2,
            $baseline,
            self::escapePdfString($encoded),
        );
    }

    private static function textAt(
        string $text,
        string $font,
        float $fontSize,
        float $red,
        float $green,
        float $blue,
        float $x,
        float $baseline,
    ): string {
        return sprintf(
            'q %.3f %.3f %.3f rg BT /%s %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET Q',
            $red,
            $green,
            $blue,
            $font,
            $fontSize,
            $x,
            $baseline,
            self::escapePdfString(self::encodeText($text)),
        );
    }

    private static function textWidth(string $text, float $fontSize, bool $bold): float
    {
        $widths = [
            ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191,
            '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278,
            '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556,
            ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584, '?' => 556, '@' => 1015,
            'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278, 'J' => 500,
            'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611,
            'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 278, '\\' => 278, ']' => 278, '^' => 469,
            '_' => 556, '`' => 333, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556, 'h' => 556,
            'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556, 'p' => 556, 'q' => 556, 'r' => 333,
            's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 500, '{' => 334, '|' => 260,
            '}' => 334, '~' => 584,
        ];
        $units = 0;

        foreach (str_split($text) as $character) {
            $units += $widths[$character] ?? 556;
        }

        return ($units * $fontSize / 1000) * ($bold ? 1.04 : 1);
    }

    private static function encodeText(string $text): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        return is_string($encoded) ? $encoded : preg_replace('/[^\x20-\x7E]/', '?', $text);
    }

    private static function escapePdfString(string $text): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], $text);
    }

    /** @param array<int, string> $objects */
    private static function buildPdf(array $objects): string
    {
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        for ($number = 1; $number <= count($objects); $number++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
    }
}
