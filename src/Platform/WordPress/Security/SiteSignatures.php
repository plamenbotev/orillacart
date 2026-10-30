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
 * What the key used to be is known here too, so what was sealed with it still opens (Secrets).
 */
final class SiteSignatures
{
    public static function create(): Signatures
    {
        return new Signatures(static fn(): string => self::secret(wp_salt('auth'), get_current_blog_id()));
    }

    /** The shop's secrets: sealed with the site's key, opened with it or with one the shop sealed with before. */
    public static function createSecrets(): Secrets
    {
        return new Secrets(self::create(), self::earlier());
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

    /**
     * @return list<Signatures> keyed with each earlier secret, read once needed (wp_salt() exists only after plugins load)
     */
    public static function earlier(): array
    {
        return array_map(
            static fn(int $i): Signatures => new Signatures(static fn(): string => self::earlierSecrets(wp_salt('auth'))[$i]),
            array_keys(self::earlierSecrets('')),
        );
    }
}
