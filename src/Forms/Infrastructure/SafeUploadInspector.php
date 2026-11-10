<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Infrastructure;

use OrillaCart\Forms\Application\FormUploads;
use OrillaCart\Forms\Application\TemporaryUploads;
use OrillaCart\Forms\Domain\FormRuleViolation;
use OrillaCart\Forms\Domain\UploadInspector;

/**
 * Pictures are measured before they are opened, so a small file cannot unpack to gigabytes, then decoded by GD and
 * written again: whatever hid in or after them, and their metadata (a phone's position), is gone. A PDF is read
 * through, its compressed streams too, and refused when anything in it asks to run, opens files or addresses,
 * plays media or is encrypted (then nothing in it can be read): what it may do is jump to its own pages.
 */
final readonly class SafeUploadInspector implements UploadInspector
{
    public const int MAX_PIXELS = 25_000_000;
    public const int MAX_SIDE = 10_000;
    /** The largest photo turned upright as its camera noted. */
    public const int MAX_TURNED = 12_000_000;
    /** The most the compressed streams of a PDF may unpack to, all together. */
    private const int MAX_PDF_UNPACKED = 64 * 1024 * 1024;
    /** The most streams a PDF may have: more is not a picture or a document to print. */
    private const int MAX_PDF_STREAMS = 10_000;
    /** PDF's white space: NUL, tab, line feed, form feed, carriage return and space. */
    private const string WHITE = "\0\t\n\f\r ";
    /** What ends a name or a number besides white space. */
    private const string DELIMITERS = '()<>[]{}/%';
    /**
     * The names of what runs, opens other files or addresses, plays media, sends the form, or hides what is
     * inside, wherever they stand; also written as #xx escapes. A name ends at white space (NUL too) or a
     * delimiter.
     */
    private const string PDF_ACTIONS = '/\/(?:JavaScript|JS|Launch|EmbeddedFiles?|EF|Filespec|FileAttachment|OpenAction|AA|RichMedia|RichMediaExecute|XFA|SubmitForm|ResetForm|ImportData|GoToE|GoToR|GoToDp|GoTo3DView|3D|URI|Named|Rendition|Movie|Sound|Screen|SetOCGState|Hide|Encrypt|AcroForm)(?=[\x00\t\n\f\r \/\[\]<>(){}%]|$)/';
    /**
     * An action's type (its /S) that is not a jump to a page of the same document: of the action types a reader
     * knows, GoTo is the only one taken. Other uses of /S (page labels, transitions, structure) name none of these.
     */
    private const string PDF_ACTION_TYPES = '/\/S(?:[\x00\t\n\f\r ]|%[^\r\n]*)*\/(?!GoTo(?=[\x00\t\n\f\r \/\[\]<>(){}%]|$))(?:GoTo[A-Za-z0-9]+|Launch|Thread|URI|Sound|Movie|Hide|Named|SubmitForm|ResetForm|ImportData|SetOCGState|Rendition|Trans|JavaScript|RichMediaExecute|ECMAScript)(?=[\x00\t\n\f\r \/\[\]<>(){}%]|$)/';
    /** An object's header, "12 0 obj", wherever one may begin. */
    private const string OBJECT_HEADER = '/(?<![0-9])[0-9]+[\x00\t\n\f\r ]+[0-9]+[\x00\t\n\f\r ]+obj(?=[\x00\t\n\f\r \/\[<(%]|$)/';

    /**
     * @param TemporaryUploads|null $temporaries where the clean copies are made; the system's temporary directory, named for no site, without
     */
    public function __construct(private ?TemporaryUploads $temporaries = null) {}

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

    public function clean(string $path, string $type, bool $links = false): string
    {
        return $type === 'application/pdf' ? $this->pdf($path, $links) : $this->image($path, $type);
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
            $clean = $this->temporary();
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
     *
     * Which encoding a stream has is read from its dictionary, parsed from its object's header to the stream
     * keyword. Every header since the stream before is tried, so one planted in a string or a comment cannot hide
     * the real one: the stream is read only when every reading that ends at its keyword says the same, and the
     * file is refused when none does, or the encoding is anything but names (a reference, say).
     */
    private function pdf(string $path, bool $links): string
    {
        $bytes = file_get_contents($path);
        if ($bytes === false || !str_starts_with($bytes, '%PDF-')) {
            throw new FormRuleViolation('This PDF cannot be read.', 'orillacart_invalid_upload');
        }
        if (self::acts($bytes, $links)) {
            throw self::acting($links);
        }
        $unpacked = 0;
        $streams = 0;
        $at = 0;
        $since = 0;
        // Parsing dictionaries costs no more than reading the file a few times over.
        $budget = 4 * \strlen($bytes) + 1024 * 1024;
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
            $stream = self::streamDictionary($bytes, $since, $found, $budget);
            $at = $end + 9;
            $since = $at;
            [$filters, $picture] = $stream;
            if ($filters === []) {
                continue; // Read with the rest of the file above.
            }
            if (array_diff($filters, ['FlateDecode']) !== []) {
                if ($picture && array_diff($filters, ['FlateDecode', 'DCTDecode', 'JPXDecode', 'CCITTFaxDecode', 'JBIG2Decode']) === []) {
                    continue;
                }
                throw self::encoded();
            }
            $unpacked += self::scanInflated(rtrim(substr($bytes, $begin, $end - $begin), "\r\n"), self::MAX_PDF_UNPACKED - $unpacked, $links);
        }
        $clean = $this->temporary();
        if (file_put_contents($clean, $bytes) !== \strlen($bytes)) {
            throw new \RuntimeException('The PDF could not be copied.');
        }

        return $clean;
    }

    /**
     * The encodings of the stream whose keyword is at $keyword, and whether it is a picture: as every object
     * header between $since and the keyword reads it, when those that end at the keyword agree.
     *
     * @return array{0: list<string>, 1: bool}
     */
    private static function streamDictionary(string $bytes, int $since, int $keyword, int &$budget): array
    {
        if (preg_match_all(self::OBJECT_HEADER, substr($bytes, $since, $keyword - $since), $headers, \PREG_OFFSET_CAPTURE) < 1) {
            throw self::complex();
        }
        $readings = [];
        foreach ($headers[0] as [$header, $offset]) {
            $position = $since + $offset + \strlen($header);
            try {
                $dictionary = self::value($bytes, $position, $budget, 0);
            } catch (\UnexpectedValueException) {
                continue; // Not an object from here: another header is the stream's.
            }
            self::skipWhite($bytes, $position);
            if ($position !== $keyword || !\is_array($dictionary) || ($dictionary['kind'] ?? null) !== 'dictionary') {
                continue;
            }
            $readings[] = self::encodings($dictionary);
        }
        $readings = array_values(array_unique(array_map(serialize(...), $readings)));
        if (\count($readings) !== 1) {
            // No reading ends at the keyword, or two disagree: what a reader takes it for is not certain.
            throw self::encoded();
        }
        /** @var array{0: list<string>, 1: bool} */
        return unserialize($readings[0]);
    }

    /**
     * What a stream's dictionary says of its data: its encodings, and whether it is a picture. Every /Filter in
     * it counts, nested ones too; one that is not a name or a list of names, or data in another file, refuses it.
     *
     * @param array{kind: string, entries?: array<string, mixed>, items?: list<mixed>} $dictionary
     *
     * @return array{0: list<string>, 1: bool}
     */
    private static function encodings(array $dictionary): array
    {
        $entries = $dictionary['entries'] ?? [];
        if (\array_key_exists('F', $entries) || \array_key_exists('FFilter', $entries)) {
            throw self::encoded();
        }
        $filters = [];
        $walk = static function (mixed $value) use (&$walk, &$filters): void {
            if (!\is_array($value)) {
                return;
            }
            foreach (\is_array($value['entries'] ?? null) ? $value['entries'] : [] as $key => $entry) {
                if ($key === 'Filter') {
                    $names = \is_array($entry) && ($entry['kind'] ?? null) === 'array' && \is_array($entry['items'] ?? null) ? $entry['items'] : [$entry];
                    foreach ($names as $name) {
                        if (!\is_array($name) || ($name['kind'] ?? null) !== 'name' || !\is_string($name['name'] ?? null)) {
                            throw self::encoded();
                        }
                        $filters[] = $name['name'];
                    }
                }
                $walk($entry);
            }
            foreach (\is_array($value['items'] ?? null) ? $value['items'] : [] as $item) {
                $walk($item);
            }
        };
        $walk($dictionary);
        $name = static fn(string $key): ?string => \is_array($entries[$key] ?? null) && ($entries[$key]['kind'] ?? null) === 'name' && \is_string($entries[$key]['name'] ?? null) ? $entries[$key]['name'] : null;
        $picture = $name('Subtype') === 'Image' && !\in_array($name('Type'), ['ObjStm', 'XRef'], true);

        return [array_values(array_unique($filters)), $picture];
    }

    /**
     * One PDF object read from $position on, which is left after it: a dictionary or an array with what is in
     * it, a name, or null for anything else (numbers, strings, references). Throws when it is not an object.
     *
     * @return array{kind: string, name?: string, entries?: array<string, mixed>, items?: list<mixed>}|null
     *
     * @throws \UnexpectedValueException
     */
    private static function value(string $bytes, int &$position, int &$budget, int $depth): ?array
    {
        self::skipWhite($bytes, $position);
        $start = $position;
        if ($depth > 64 || $position >= \strlen($bytes)) {
            throw new \UnexpectedValueException('No object here.');
        }
        $char = $bytes[$position];
        $value = null;
        if (substr($bytes, $position, 2) === '<<') {
            $position += 2;
            $entries = [];
            while (true) {
                self::skipWhite($bytes, $position);
                if (substr($bytes, $position, 2) === '>>') {
                    $position += 2;
                    break;
                }
                $key = self::value($bytes, $position, $budget, $depth + 1);
                if (($key['kind'] ?? null) !== 'name' || !\is_string($key['name'] ?? null)) {
                    throw new \UnexpectedValueException('A dictionary key is a name.');
                }
                $entries[$key['name']] = self::value($bytes, $position, $budget, $depth + 1);
                self::reference($bytes, $position);
            }
            $value = ['kind' => 'dictionary', 'entries' => $entries];
        } elseif ($char === '[') {
            ++$position;
            $items = [];
            while (true) {
                self::skipWhite($bytes, $position);
                if (($bytes[$position] ?? ']') === ']') {
                    if (!isset($bytes[$position])) {
                        throw new \UnexpectedValueException('An array is not closed.');
                    }
                    ++$position;
                    break;
                }
                $items[] = self::value($bytes, $position, $budget, $depth + 1);
                self::reference($bytes, $position);
            }
            $value = ['kind' => 'array', 'items' => $items];
        } elseif ($char === '/') {
            ++$position;
            $length = strcspn($bytes, self::WHITE . self::DELIMITERS, $position);
            $value = ['kind' => 'name', 'name' => self::plain(substr($bytes, $position, $length))];
            $position += $length;
        } elseif ($char === '(') {
            $nesting = 0;
            for ($length = \strlen($bytes); $position < $length; ++$position) {
                $c = $bytes[$position];
                if ($c === '\\') {
                    ++$position;
                } elseif ($c === '(') {
                    ++$nesting;
                } elseif ($c === ')' && --$nesting === 0) {
                    break;
                }
            }
            if ($position >= \strlen($bytes)) {
                throw new \UnexpectedValueException('A string is not closed.');
            }
            ++$position;
        } elseif ($char === '<') {
            $close = strpos($bytes, '>', $position);
            if ($close === false || strspn($bytes, '0123456789abcdefABCDEF' . self::WHITE, $position + 1) !== $close - $position - 1) {
                throw new \UnexpectedValueException('A hexadecimal string is not closed.');
            }
            $position = $close + 1;
        } else {
            $length = strcspn($bytes, self::WHITE . self::DELIMITERS, $position);
            $token = substr($bytes, $position, $length);
            // Numbers and the plain words an object may be; "endobj", "stream" and the like end it.
            if (preg_match('/^(?:[+-]?(?:[0-9]+\.?[0-9]*|\.[0-9]+)|true|false|null)$/', $token) !== 1) {
                throw new \UnexpectedValueException('Not an object.');
            }
            $position += $length;
        }
        $budget -= $position - $start;
        if ($budget < 0) {
            throw self::complex();
        }

        return $value;
    }

    /** Steps over the "0 R" of a reference whose number was just read. */
    private static function reference(string $bytes, int &$position): void
    {
        if (preg_match('/\G[\x00\t\n\f\r ]+[0-9]+[\x00\t\n\f\r ]+R(?=[\x00\t\n\f\r \/\[\]<>(){}%]|$)/', $bytes, $match, 0, $position) === 1) {
            $position += \strlen($match[0]);
        }
    }

    /** Steps over white space and comments. */
    private static function skipWhite(string $bytes, int &$position): void
    {
        $length = \strlen($bytes);
        while ($position < $length) {
            $position += strspn($bytes, self::WHITE, $position);
            if (($bytes[$position] ?? '') !== '%') {
                return;
            }
            $position += strcspn($bytes, "\r\n", $position);
        }
    }

    /**
     * Unpacks a FlateDecode stream a little at a time, checking each piece (with the end of the one before, so a
     * name split between them is read whole) and keeping none: memory stays small however much it unpacks to. One
     * that does not unpack, stops before its end, or passes the room left refuses the file.
     *
     * @return int how many bytes it unpacked to
     */
    private static function scanInflated(string $packed, int $room, bool $links): int
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
            if (self::acts($tail . $part, $links)) {
                throw self::acting($links);
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

    private static function acting(bool $links): FormRuleViolation
    {
        return $links
            ? new FormRuleViolation('This PDF has scripts, actions, attachments, media, forms or encryption; send one without.', 'orillacart_invalid_upload')
            : new FormRuleViolation('This PDF has scripts, links, actions, attachments, media, forms or encryption; send one without.', 'orillacart_invalid_upload');
    }

    private static function encoded(): FormRuleViolation
    {
        return new FormRuleViolation('This PDF uses an encoding the shop cannot check; save it again as a standard PDF.', 'orillacart_invalid_upload');
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

    /**
     * Whether anything in the PDF acts. With links taken, a link to a web address (/URI, the key and the action)
     * is let through: a reader opens it only when clicked, and asks first.
     */
    private static function acts(string $bytes, bool $links): bool
    {
        $plain = self::plain($bytes);
        $actions = $links ? str_replace('|URI|', '|', self::PDF_ACTIONS) : self::PDF_ACTIONS;
        $types = $links ? str_replace('|URI|', '|', self::PDF_ACTION_TYPES) : self::PDF_ACTION_TYPES;

        return preg_match($actions, $plain) === 1 || preg_match($types, $plain) === 1;
    }

    private function temporary(): string
    {
        if ($this->temporaries !== null) {
            return $this->temporaries->create();
        }
        $path = tempnam(sys_get_temp_dir(), FormUploads::TEMPORARY_PREFIX);
        if ($path === false) {
            throw new \RuntimeException('No temporary file for the upload.');
        }

        return $path;
    }
}
