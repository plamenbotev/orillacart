<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Security;

/**
 * Seals what the shop keeps that would let someone act as it, such as a payment provider's keys, so a copy of
 * the database alone does not give them away: libsodium's secretbox, with a key of the site's salts. Changing
 * the salts loses them; the shop then asks for them again.
 */
final readonly class Secrets
{
    private const string PREFIX = 'orillacart:sealed:1:';

    public function __construct(private Signatures $signatures) {}

    public function seal(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key()));
    }

    /**
     * @return string|null the plain text; what was never sealed comes back as it is; null when it cannot be opened
     */
    public function open(string $value): ?string
    {
        if (!self::isSealed($value)) {
            return $value;
        }
        $raw = base64_decode(substr($value, \strlen(self::PREFIX)), true);
        if ($raw === false || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());

        return $plain === false ? null : $plain;
    }

    public static function isSealed(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    private function key(): string
    {
        return $this->signatures->key('secrets');
    }
}
