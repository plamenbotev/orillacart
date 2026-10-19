<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Files;

/**
 * The part of a file a Range header asks for (RFC 9110): one range of bytes, such as a browser resuming a
 * download. Several ranges at once are answered with the whole file, as the standard allows.
 */
final readonly class ByteRange
{
    private const string PATTERN = '/^bytes=(\d*)-(\d*)$/';

    public function __construct(public int $start, public int $end) {}

    /**
     * @return self|false|null null to send the whole file; false when the range starts past its end
     */
    public static function of(string $header, int $size): self|false|null
    {
        if (preg_match(self::PATTERN, trim($header), $parts) !== 1 || ($parts[1] === '' && $parts[2] === '')) {
            return null;
        }
        if ($parts[1] === '') {
            // The last N bytes.
            $suffix = (int) $parts[2];

            return $suffix === 0 || $size === 0 ? false : new self(max(0, $size - $suffix), $size - 1);
        }
        $start = (int) $parts[1];
        $end = $parts[2] === '' ? $size - 1 : min((int) $parts[2], $size - 1);
        if ($parts[2] !== '' && (int) $parts[2] < $start) {
            return null;
        }

        return $start >= $size ? false : new self($start, $end);
    }

    /** Whether the header asks for the file from past its start, as a download that broke off and goes on. */
    public static function resumes(string $header): bool
    {
        if (preg_match(self::PATTERN, trim($header), $parts) !== 1) {
            return false;
        }

        return $parts[1] === '' ? $parts[2] !== '' : (int) $parts[1] > 0 && ($parts[2] === '' || (int) $parts[2] >= (int) $parts[1]);
    }

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }
}
