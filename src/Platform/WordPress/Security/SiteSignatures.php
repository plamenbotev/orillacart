<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Security;

use OrillaCart\Shared\Security\Signatures;

/**
 * The shop's signatures, keyed with the site's authentication salt: changing the salts (as after a break-in)
 * makes every link the shop handed out stop working.
 */
final class SiteSignatures
{
    public static function create(): Signatures
    {
        return new Signatures(wp_salt('auth'));
    }
}
