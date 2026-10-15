<?php

namespace App\Services;

use App\Exceptions\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The academy's uploaded certificate artwork (A4 landscape PNG/JPG) plus where each student detail is printed on it.
 * Uploaded images are decoded and re-encoded with GD, so only a genuine bitmap is ever stored (no polyglot or metadata payloads).
 */
class CertificateTemplate
{
    public const PAGE_W = 841.89;   // A4 landscape, points
    public const PAGE_H = 595.28;

    /** key => label, x/y = centre of the text as % of the page, size = pt (QR: % of page width), width = max text width in % of the page */
    public const DEFAULTS = [
        'holder_name' => ['label' => 'Student name', 'x' => 50, 'y' => 42.5, 'size' => 34, 'width' => 72, 'color' => '#1b2b1b', 'font' => 'serif', 'visible' => true],
        'course_title' => ['label' => 'Course title', 'x' => 50, 'y' => 58, 'size' => 24, 'width' => 72, 'color' => '#207027', 'font' => 'serif', 'visible' => true],
        'issued_on' => ['label' => 'Date issued', 'x' => 50, 'y' => 68, 'size' => 13, 'width' => 50, 'color' => '#444444', 'font' => 'sans', 'visible' => true],
        'grade' => ['label' => 'Final grade', 'x' => 50, 'y' => 73, 'size' => 12, 'width' => 50, 'color' => '#444444', 'font' => 'sans', 'visible' => false],
        'duration' => ['label' => 'Course duration', 'x' => 50, 'y' => 77, 'size' => 12, 'width' => 50, 'color' => '#444444', 'font' => 'sans', 'visible' => false],
        'signatory' => ['label' => 'Signatory name and title', 'x' => 26, 'y' => 84, 'size' => 12, 'width' => 30, 'color' => '#202820', 'font' => 'sans', 'visible' => true],
        'number' => ['label' => 'Certificate number', 'x' => 50, 'y' => 90, 'size' => 10, 'width' => 40, 'color' => '#555555', 'font' => 'sans', 'visible' => true],
        'verify_url' => ['label' => 'Verification link', 'x' => 50, 'y' => 93.5, 'size' => 9, 'width' => 60, 'color' => '#555555', 'font' => 'sans', 'visible' => true],
        'qr' => ['label' => 'QR code', 'x' => 87, 'y' => 82, 'size' => 11, 'visible' => true],
    ];
    public const FONTS = ['serif' => 'DejaVu Serif', 'sans' => 'DejaVu Sans', 'times' => 'Times'];

    /** @return array{path: ?string, layout: array, meta: ?array} */
    public static function current(): array
    {
        $path = Settings::get('certificate.template_path');
        $ok = $path && Storage::disk('private')->exists($path);

        return ['path' => $ok ? $path : null, 'layout' => self::layout(Settings::get('certificate.layout') ?: []), 'meta' => $ok ? Settings::get('certificate.template_meta') : null];
    }

    /** Overlay saved values on the defaults, keeping only known fields/attributes with safe values. */
    public static function layout(array $saved): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $def) {
            $f = $def;
            $s = is_array($saved[$key] ?? null) ? $saved[$key] : [];
            foreach (['x', 'y'] as $k) {
                if (isset($s[$k]) && is_numeric($s[$k])) {
                    $f[$k] = max(0, min(100, (float) $s[$k]));
                }
            }
            if (isset($s['size']) && is_numeric($s['size'])) {
                $f['size'] = $key === 'qr' ? max(5, min(30, (float) $s['size'])) : max(6, min(72, (float) $s['size']));
            }
            if (isset($s['width']) && is_numeric($s['width']) && isset($def['width'])) {
                $f['width'] = max(10, min(100, (float) $s['width']));
            }
            if (isset($s['color']) && is_string($s['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $s['color']) && isset($def['color'])) {
                $f['color'] = strtolower($s['color']);
            }
            if (isset($s['font']) && isset(self::FONTS[$s['font']]) && isset($def['font'])) {
                $f['font'] = $s['font'];
            }
            if (isset($s['visible'])) {
                $f['visible'] = (bool) $s['visible'];
            }
            $out[$key] = $f;
        }

        return $out;
    }

    /** Validates, re-encodes and stores an uploaded image; returns the stored path and metadata. @return array{path: string, meta: array} */
    public static function store(UploadedFile $file): array
    {
        $bytes = file_get_contents($file->getRealPath());
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            throw new DomainException('The template must be a PNG or JPG image.', 'bad_template_type', 422);
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info) {
            throw new DomainException('That file is not a readable image.', 'bad_template', 422);
        }
        [$w, $h] = $info;
        if ($w < 1400 || $w > 7000 || $h > 5000) {
            throw new DomainException("The template is {$w}×{$h} pixels. Use A4 landscape at 150–300 dpi, for example 3508×2480 pixels (minimum width 1400).", 'bad_template_size', 422);
        }
        $ratio = $w / $h;
        if ($ratio < 1.30 || $ratio > 1.55) {
            throw new DomainException('The template must be A4 landscape (about 1.41:1, for example 3508×2480 pixels). Yours is '.round($ratio, 2).':1.', 'bad_template_ratio', 422);
        }
        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            throw new DomainException('That image could not be decoded.', 'bad_template', 422);
        }
        $png = $mime === 'image/png';
        ob_start();
        $png ? imagepng($img, null, 6) : imagejpeg($img, null, 92);
        $clean = ob_get_clean();
        imagedestroy($img);
        $path = 'certificate-templates/'.Str::uuid().($png ? '.png' : '.jpg');
        Storage::disk('private')->put($path, $clean);

        return ['path' => $path, 'meta' => ['width' => $w, 'height' => $h, 'mime' => $mime, 'bytes' => strlen($clean), 'uploaded_at' => now()->toIso8601String()]];
    }

    /**
     * Fields ready for the PDF view: absolute points, escaped later by Blade. Hidden fields and fields without data are dropped.
     * @param  array<string, string|null>  $text
     */
    public static function placed(array $layout, array $text): array
    {
        $out = [];
        foreach ($layout as $key => $f) {
            if (! ($f['visible'] ?? false) || $key === 'qr' || ($text[$key] ?? '') === '') {
                continue;
            }
            $maxW = $f['width'] / 100 * self::PAGE_W;
            $len = max(1, mb_strlen($text[$key]) * (isset($f['font']) && $f['font'] === 'sans' ? 0.58 : 0.52));
            $size = min($f['size'], $maxW / $len);       // long names shrink to fit rather than wrapping off the page
            $lines = $key === 'signatory' ? 2 : 1;
            $out[$key] = ['text' => $text[$key], 'size' => round(max($size, 7), 2), 'left' => round($f['x'] / 100 * self::PAGE_W - $maxW / 2, 2), 'top' => round($f['y'] / 100 * self::PAGE_H - $size * 0.62 * $lines, 2),
                'width' => round($maxW, 2), 'color' => $f['color'], 'font' => self::FONTS[$f['font']]];
        }

        return $out;
    }

    public static function qrBox(array $layout): ?array
    {
        $q = $layout['qr'];
        if (! $q['visible']) {
            return null;
        }
        $s = $q['size'] / 100 * self::PAGE_W;

        return ['size' => round($s, 2), 'left' => round($q['x'] / 100 * self::PAGE_W - $s / 2, 2), 'top' => round($q['y'] / 100 * self::PAGE_H - $s / 2, 2)];
    }
}
