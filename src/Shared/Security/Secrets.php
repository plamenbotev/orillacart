<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Security;

/**
 * Seals what the shop keeps that would let someone act as it, such as a payment provider's keys, so a copy of
 * the database alone does not give them away: libsodium's secretbox, with a key of the site's salts. Changing
 * the salts loses them; the shop then asks for them again. A key the shop sealed with earlier (the salt alone,
 * before the site's id took part) still opens what it sealed, until that is sealed again with the current key.
 */
final readonly class Secrets
{
    private const string PREFIX = 'orillacart:sealed:1:';

    /**
     * @param list<Signatures> $earlier the keys the shop sealed with before the current one, newest first
     */
    public function __construct(private Signatures $signatures, private array $earlier = []) {}

    public function seal(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key($this->signatures)));
    }

    /**
     * @return string|null the plain text; what was never sealed comes back as it is; null when it cannot be opened
     */
    public function open(string $value): ?string
    {
        if (!self::isSealed($value)) {
            return $value;
        }
        $raw = self::raw($value);
        if ($raw === null) {
            return null;
        }
        foreach ([$this->signatures, ...$this->earlier] as $signatures) {
            $plain = self::openWith($raw, $this->key($signatures));
            if ($plain !== null) {
                return $plain;
            }
        }

        return null;
    }

    /**
     * Whether the value is sealed with the current key: what is sealed with an earlier one, or not sealed at all,
     * is sealed again with it on its next save, or by `wp orillacart doctor --fix` all at once.
     */
    public function sealedWithCurrentKey(string $value): bool
    {
        if (!self::isSealed($value)) {
            return false;
        }
        $raw = self::raw($value);

        return $raw !== null && self::openWith($raw, $this->key($this->signatures)) !== null;
    }

    public static function isSealed(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /** The nonce and the box, or null when the value is not what seal() makes. */
    private static function raw(string $value): ?string
    {
        $raw = base64_decode(substr($value, \strlen(self::PREFIX)), true);

        return $raw === false || \strlen($raw) < \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES ? null : $raw;
    }

    private static function openWith(string $raw, #[\SensitiveParameter] string $key): ?string
    {
        $plain = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);

        return $plain === false ? null : $plain;
    }

    private function key(Signatures $signatures): string
    {
        return $signatures->key('secrets');
    }
}
