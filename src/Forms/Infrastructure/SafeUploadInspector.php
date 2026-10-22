<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Infrastructure;

use OrillaCart\Forms\Domain\FormRuleViolation;
use OrillaCart\Forms\Domain\UploadInspector;

/**
 * Pictures are measured before they are opened, so a small file cannot unpack to gigabytes, then decoded by GD and
 * written again: whatever hid in or after them, and their metadata (a phone's position), is gone. A PDF is read
 * through, its compressed streams too, and refused when anything in it asks to run, opens files or is encrypted
 * (then nothing in it can be read).
 */
final readonly class SafeUploadInspector implements UploadInspector
{
    public const int MAX_PIXELS = 25_000_000;
    public const int MAX_SIDE = 10_000;
    /** The most the compressed streams of a PDF may unpack to, all together. */
    private const int MAX_PDF_UNPACKED = 64 * 1024 * 1024;
    /** What runs, opens other files, sends the form, or hides what is inside; also written as #xx escapes. */
    private const string PDF_ACTIONS = '/\/(?:JavaScript|JS|Launch|EmbeddedFiles?|OpenAction|AA|RichMedia|XFA|SubmitForm|ImportData|GoToE|GoToR|Encrypt|AcroForm)(?=[\s\/\[\]<>()%{}]|$)/';

    public function type(string $path): ?string
    {
        $type = new \finfo(\FILEINFO_MIME_TYPE)->file($path);

        return \is_string($type) ? $type : null;
    }

    public function takesImages(): bool
    {
        return \function_exists('imagecreatefromstring') && \function_exists('imagepng') && \function_exists('imagejpeg');
    }

    public function clean(string $path, string $type): string
    {
        return $type === 'application/pdf' ? $this->pdf($path) : $this->image($path, $type);
    }

    private function image(string $path, string $type): string
    {
        if (!$this->takesImages()) {
            throw new FormRuleViolation('Pictures cannot be taken here; send a PDF.', 'orillacart_invalid_upload');
        }
        $size = @getimagesize($path);
        $types = ['image/jpeg' => \IMAGETYPE_JPEG, 'image/png' => \IMAGETYPE_PNG, 'image/gif' => \IMAGETYPE_GIF, 'image/webp' => \IMAGETYPE_WEBP];
        if ($size === false || ($types[$type] ?? null) !== $size[2] || $size[0] < 1 || $size[1] < 1) {
            throw new FormRuleViolation('This picture cannot be read.', 'orillacart_invalid_upload');
        }
        if ($size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw new FormRuleViolation(\sprintf('The picture is too large: up to %d megapixels and %d pixels a side.', intdiv(self::MAX_PIXELS, 1_000_000), self::MAX_SIDE), 'orillacart_invalid_upload');
        }
        if (\function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('image');
        }
        $bytes = file_get_contents($path);
        $image = $bytes === false ? false : @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new FormRuleViolation('This picture cannot be read.', 'orillacart_invalid_upload');
        }
        try {
            if ($type === 'image/jpeg') {
                $image = self::upright($image, $path);
            }
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $clean = self::temporary();
            $written = match ($type) {
                'image/jpeg' => imagejpeg($image, $clean, 92),
                'image/png' => imagepng($image, $clean, 6),
                'image/gif' => imagegif($image, $clean),
                'image/webp' => \function_exists('imagewebp') && imagewebp($image, $clean, 90),
                default => false,
            };
        } finally {
            unset($image);
        }
        if (!$written || (int) filesize($clean) === 0) {
            @unlink($clean);
            throw new FormRuleViolation('This picture cannot be read.', 'orillacart_invalid_upload');
        }

        return $clean;
    }

    /** A photo turned as the camera said: writing it again drops the note that turned it. */
    private static function upright(\GdImage $image, string $path): \GdImage
    {
        $exif = \function_exists('exif_read_data') ? @exif_read_data($path) : false;
        $orientation = \is_array($exif) && \is_int($exif['Orientation'] ?? null) ? $exif['Orientation'] : 1;
        $angle = match ($orientation) {
            3 => 180,
            6 => 270,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $turned = imagerotate($image, $angle, 0);

        return $turned === false ? $image : $turned;
    }

    private function pdf(string $path): string
    {
        $bytes = file_get_contents($path);
        if ($bytes === false || !str_starts_with($bytes, '%PDF-')) {
            throw new FormRuleViolation('This PDF cannot be read.', 'orillacart_invalid_upload');
        }
        if (self::acts($bytes)) {
            throw new FormRuleViolation('This PDF has scripts, actions, attachments, forms or encryption; send one without.', 'orillacart_invalid_upload');
        }
        // Dictionaries may hide in compressed object streams: each is unpacked and read too, within a limit.
        $unpacked = 0;
        if (preg_match_all('/stream\r?\n/', $bytes, $starts, \PREG_OFFSET_CAPTURE) !== false) {
            foreach ($starts[0] as [$marker, $offset]) {
                $begin = $offset + \strlen($marker);
                $end = strpos($bytes, 'endstream', $begin);
                if ($end === false) {
                    break;
                }
                $data = @zlib_decode(substr($bytes, $begin, $end - $begin), self::MAX_PDF_UNPACKED - $unpacked + 1);
                if (!\is_string($data)) {
                    continue;
                }
                $unpacked += \strlen($data);
                if ($unpacked > self::MAX_PDF_UNPACKED) {
                    throw new FormRuleViolation('This PDF is too complex to check; send a simpler one.', 'orillacart_invalid_upload');
                }
                if (self::acts($data)) {
                    throw new FormRuleViolation('This PDF has scripts, actions, attachments, forms or encryption; send one without.', 'orillacart_invalid_upload');
                }
            }
        }
        $clean = self::temporary();
        if (file_put_contents($clean, $bytes) !== \strlen($bytes)) {
            throw new \RuntimeException('The PDF could not be copied.');
        }

        return $clean;
    }

    /** Names with #xx escapes are read as written plainly: /J#61vaScript is /JavaScript. */
    private static function acts(string $bytes): bool
    {
        $plain = (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn(array $hex): string => \chr((int) hexdec($hex[1])), $bytes);

        return preg_match(self::PDF_ACTIONS, $plain) === 1;
    }

    private static function temporary(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'orillacart-upload');
        if ($path === false) {
            throw new \RuntimeException('No temporary file for the upload.');
        }

        return $path;
    }
}
