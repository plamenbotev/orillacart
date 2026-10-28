<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Infrastructure;

use OrillaCart\Forms\Application\FormUploads;
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
    /** The largest photo turned upright as its camera noted. */
    public const int MAX_TURNED = 12_000_000;
    /** What temporary files of uploads are called, for sweeping those a failed request left. */
    public const string TEMPORARY_PREFIX = FormUploads::TEMPORARY_PREFIX;
    /** The most the compressed streams of a PDF may unpack to, all together. */
    private const int MAX_PDF_UNPACKED = 64 * 1024 * 1024;
    /** The most streams a PDF may have: more is not a picture or a document to print. */
    private const int MAX_PDF_STREAMS = 10_000;
    /** What runs, opens other files, sends the form, or hides what is inside; also written as #xx escapes. */
    private const string PDF_ACTIONS = '/\/(?:JavaScript|JS|Launch|EmbeddedFiles?|OpenAction|AA|RichMedia|XFA|SubmitForm|ImportData|GoToE|GoToR|Encrypt|AcroForm)(?=[\s\/\[\]<>()%{}]|$)/';

    public function type(string $path): ?string
    {
        // Without fileinfo nothing tells what a file is, so none is taken.
        if (!$this->readsTypes()) {
            return null;
        }
        $type = new \finfo(\FILEINFO_MIME_TYPE)->file($path);

        return \is_string($type) ? $type : null;
    }

    public function readsTypes(): bool
    {
        return class_exists(\finfo::class);
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
            throw new FormRuleViolation('The picture is too large: up to %1$d megapixels and %2$d pixels a side.', 'orillacart_invalid_upload', values: [intdiv(self::MAX_PIXELS, 1_000_000), self::MAX_SIDE]);
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
        // Turning holds the picture twice: past MAX_TURNED pixels it stays as it was taken, within memory.
        if ($angle === 0 || imagesx($image) * imagesy($image) > self::MAX_TURNED) {
            return $image;
        }
        $turned = imagerotate($image, $angle, 0);

        return $turned === false ? $image : $turned;
    }

    /**
     * Fails closed: every stream is read, or the file is refused. A stream is unpacked when FlateDecode is all it
     * uses (and it has to unpack, within the limit); a picture's own encodings (DCT, JPX, CCITT, JBIG2) are taken
     * for pictures only; any other encoding, an object or cross-reference stream that does not unpack, or a stream
     * the shop cannot find the end of refuses the file.
     */
    private function pdf(string $path): string
    {
        $bytes = file_get_contents($path);
        if ($bytes === false || !str_starts_with($bytes, '%PDF-')) {
            throw new FormRuleViolation('This PDF cannot be read.', 'orillacart_invalid_upload');
        }
        if (self::acts($bytes)) {
            throw self::acting();
        }
        $unpacked = 0;
        $streams = 0;
        $at = 0;
        while (($found = strpos($bytes, 'stream', $at)) !== false) {
            $at = $found + 6;
            // "endstream" holds "stream" too; only a stream's opening keyword is followed by an end of line.
            if (($found >= 3 && substr($bytes, $found - 3, 3) === 'end') || !\in_array($bytes[$at] ?? '', ["\r", "\n"], true)) {
                continue;
            }
            if (++$streams > self::MAX_PDF_STREAMS) {
                throw self::complex();
            }
            $begin = $at + (substr($bytes, $at, 2) === "\r\n" ? 2 : 1);
            $end = strpos($bytes, 'endstream', $begin);
            if ($end === false) {
                throw new FormRuleViolation('This PDF cannot be read.', 'orillacart_invalid_upload');
            }
            // The stream's dictionary: from its object's header ("12 0 obj") on. Every /Filter in it counts, so a
            // nested dictionary cannot hide the one a reader uses.
            $window = self::plain(substr($bytes, max(0, $found - 8192), $found - max(0, $found - 8192)));
            if (preg_match_all('~\d+\s+\d+\s+obj\b~', $window, $headers, \PREG_OFFSET_CAPTURE) < 1) {
                throw self::complex();
            }
            $header = $headers[0][\count($headers[0]) - 1];
            $dictionary = substr($window, max(0, $header[1]));
            $filters = [];
            if (preg_match_all('~/Filter\s*(\[[^\]]*\]|/[A-Za-z0-9]+)~', $dictionary, $declared) > 0) {
                foreach ($declared[1] as $list) {
                    preg_match_all('~/([A-Za-z0-9]+)~', $list, $names);
                    array_push($filters, ...$names[1]);
                }
            }
            $at = $end + 9;
            if ($filters === []) {
                continue; // Read with the rest of the file above.
            }
            $picture = preg_match('~/Subtype\s*/Image\b~', $dictionary) === 1 && preg_match('~/Type\s*/(ObjStm|XRef)\b~', $dictionary) !== 1;
            if (array_diff($filters, ['FlateDecode']) !== []) {
                if ($picture && array_diff($filters, ['FlateDecode', 'DCTDecode', 'JPXDecode', 'CCITTFaxDecode', 'JBIG2Decode']) === []) {
                    continue;
                }
                throw new FormRuleViolation('This PDF uses an encoding the shop cannot check; save it again as a standard PDF.', 'orillacart_invalid_upload');
            }
            $unpacked += self::scanInflated(rtrim(substr($bytes, $begin, $end - $begin), "\r\n"), self::MAX_PDF_UNPACKED - $unpacked);
        }
        $clean = self::temporary();
        if (file_put_contents($clean, $bytes) !== \strlen($bytes)) {
            throw new \RuntimeException('The PDF could not be copied.');
        }

        return $clean;
    }

    /**
     * Unpacks a FlateDecode stream a little at a time, checking each piece (with the end of the one before, so a
     * name split between them is read whole) and keeping none: memory stays small however much it unpacks to. One
     * that does not unpack, stops before its end, or passes the room left refuses the file.
     *
     * @return int how many bytes it unpacked to
     */
    private static function scanInflated(string $packed, int $room): int
    {
        $inflater = inflate_init(\ZLIB_ENCODING_DEFLATE);
        if ($inflater === false) {
            throw new \RuntimeException('zlib cannot unpack.');
        }
        $unpacked = 0;
        $tail = '';
        // 4 KB at a time unpacks to 4 MB at most (deflate packs about 1000:1 at best).
        foreach (str_split($packed, 4096) as $piece) {
            $part = @inflate_add($inflater, $piece, \ZLIB_SYNC_FLUSH);
            if ($part === false) {
                throw new FormRuleViolation('This PDF cannot be read.', 'orillacart_invalid_upload');
            }
            $unpacked += \strlen($part);
            if ($unpacked > $room) {
                throw self::complex();
            }
            if (self::acts($tail . $part)) {
                throw self::acting();
            }
            $tail = substr($tail . $part, -64);
            if (inflate_get_status($inflater) === \ZLIB_STREAM_END) {
                break;
            }
        }
        // A stream that stops before its end hides the rest from the check: refused.
        if (inflate_get_status($inflater) !== \ZLIB_STREAM_END) {
            throw new FormRuleViolation('This PDF cannot be read.', 'orillacart_invalid_upload');
        }

        return $unpacked;
    }

    private static function acting(): FormRuleViolation
    {
        return new FormRuleViolation('This PDF has scripts, actions, attachments, forms or encryption; send one without.', 'orillacart_invalid_upload');
    }

    private static function complex(): FormRuleViolation
    {
        return new FormRuleViolation('This PDF is too complex to check; send a simpler one.', 'orillacart_invalid_upload');
    }

    /** Names with #xx escapes as written plainly: /J#61vaScript is /JavaScript. */
    private static function plain(string $bytes): string
    {
        return (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn(array $hex): string => pack('H2', $hex[1]), $bytes);
    }

    private static function acts(string $bytes): bool
    {
        return preg_match(self::PDF_ACTIONS, self::plain($bytes)) === 1;
    }

    private static function temporary(): string
    {
        $path = tempnam(sys_get_temp_dir(), self::TEMPORARY_PREFIX);
        if ($path === false) {
            throw new \RuntimeException('No temporary file for the upload.');
        }

        return $path;
    }
}
