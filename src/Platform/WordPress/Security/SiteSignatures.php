<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Security;

use OrillaCart\Shared\Security\Signatures;

/**
 * The shop's signatures, keyed with the site's authentication salt and the site's id: changing the salts (as
 * after a break-in) makes every link the shop handed out stop working, and on a network, where every site shares
 * the salts, a token one shop made (a receipt link, a payment's return address) is no good at another.
 */
final class SiteSignatures
{
    public static function create(): Signatures
    {
        return new Signatures(static fn(): string => self::secret(wp_salt('auth'), get_current_blog_id()));
    }

    /** One site's secret: the salt every site of the network shares, told apart by the site's id (1 on a single site). */
    public static function secret(#[\SensitiveParameter] string $salt, int $siteId): string
    {
        return $salt . '|' . $siteId;
    }
}
