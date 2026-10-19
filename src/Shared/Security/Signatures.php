<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Security;

/**
 * Signs what the shop hands out and reads back (a receipt link, a payment's return address), so only what it
 * made itself is taken: an HMAC with the site's own secret, one key per purpose.
 */
final readonly class Signatures
{
    private const int LENGTH = 32;

    /**
     * @param string|\Closure(): string $secret the site's, as wp_salt() gives it, or how to read it once needed
     *                                         (WordPress defines wp_salt() only after plugins load)
     */
    public function __construct(#[\SensitiveParameter] private string|\Closure $secret) {}

    /** @return string 32 hexadecimal characters */
    public function sign(string $purpose, string $data): string
    {
        return substr(hash_hmac('sha256', $data, $this->key($purpose)), 0, self::LENGTH);
    }

    public function verify(string $purpose, string $data, string $signature): bool
    {
        return \strlen($signature) === self::LENGTH && hash_equals($this->sign($purpose, $data), $signature);
    }

    /** A key of the site's for one purpose, so a signature made for one is no good for another. */
    public function key(string $purpose): string
    {
        return hash_hmac('sha256', "orillacart:{$purpose}", \is_string($this->secret) ? $this->secret : ($this->secret)(), true);
    }
}
