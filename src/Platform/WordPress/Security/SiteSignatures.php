<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Security;

use OrillaCart\Shared\Security\Secrets;
use OrillaCart\Shared\Security\Signatures;

/**
 * The shop's signatures, keyed with the site's authentication salt and the site's id: changing the salts (as
 * after a break-in) makes every link the shop handed out stop working, and on a network, where every site shares
 * the salts, a token one shop made (a receipt link, a payment's return address) is no good at another.
 *
 * What the key used to be is known here too, so what was sealed with it still opens (Secrets). Secrets may be
 * sealed with a key of their own, ORILLACART_SECRETS_KEY, apart from the salts.
 */
final class SiteSignatures
{
    public static function create(): Signatures
    {
        return new Signatures(static fn(): string => self::secret(wp_salt('auth'), get_current_blog_id()));
    }

    /**
     * The shop's secrets: sealed with the site's key, opened with it or with one the shop sealed with before.
     * With ORILLACART_SECRETS_KEY defined in wp-config.php, that key seals them instead of the salts, which may
     * live in the database (SecretsHealth); what the salts sealed still opens until it is sealed again.
     */
    public static function createSecrets(): Secrets
    {
        return self::secrets(self::secretsKey(), static fn(): string => wp_salt('auth'), static fn(): int => get_current_blog_id());
    }

    /**
     * @param \Closure(): string $salt   the salt every site of the network shares, read once needed
     * @param \Closure(): int    $siteId
     */
    public static function secrets(#[\SensitiveParameter] ?string $ownKey, \Closure $salt, \Closure $siteId): Secrets
    {
        $salted = new Signatures(static fn(): string => self::secret($salt(), $siteId()));
        $earlier = array_map(
            static fn(int $i): Signatures => new Signatures(static fn(): string => self::earlierSecrets($salt())[$i]),
            array_keys(self::earlierSecrets('')),
        );

        return $ownKey === null
            ? new Secrets($salted, $earlier)
            : new Secrets(new Signatures(static fn(): string => self::secret($ownKey, $siteId())), [$salted, ...$earlier]);
    }

    /** ORILLACART_SECRETS_KEY, when wp-config.php defines it. */
    public static function secretsKey(): ?string
    {
        if (!\defined('ORILLACART_SECRETS_KEY')) {
            return null;
        }
        $key = \constant('ORILLACART_SECRETS_KEY');

        return \is_string($key) && trim($key) !== '' ? $key : null;
    }

    /**
     * Whether the salts the shop signs with are kept in the database rather than in wp-config.php: AUTH_KEY or
     * AUTH_SALT undefined, empty, or the sample phrase. WordPress then makes them up and stores them, so a copy of
     * the database holds them, and a plugin that rotates them in the database loses what they sealed.
     */
    public static function saltsInDatabase(): bool
    {
        foreach (['AUTH_KEY', 'AUTH_SALT'] as $name) {
            $value = \defined($name) ? \constant($name) : null;
            if (!\is_string($value) || trim($value) === '' || $value === 'put your unique phrase here') {
                return true;
            }
        }

        return false;
    }

    /** One site's secret: the salt every site of the network shares, told apart by the site's id (1 on a single site). */
    public static function secret(#[\SensitiveParameter] string $salt, int $siteId): string
    {
        return $salt . '|' . $siteId;
    }

    /**
     * The secrets the shop signed and sealed with before the current one, newest first: the salt alone, from
     * before the site's id took part (7d13ced). Each stays until nothing sealed with it can be left.
     *
     * @return list<string>
     */
    public static function earlierSecrets(#[\SensitiveParameter] string $salt): array
    {
        return [$salt];
    }
}
