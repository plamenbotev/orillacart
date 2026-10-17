<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Security;

/**
 * The WordPress capabilities the shop checks. Administrators get them on activation.
 */
final class Capability
{
    /** Shop settings, catalog rules, orders: everything in the shop's admin screens. */
    public const string MANAGE_SHOP = 'manage_orillacart';

    /** A customer's own account: WordPress's "read", which every logged-in user has. */
    public const string ACCOUNT = 'read';

    /** @return list<string> the shop's own capabilities, which the plugin grants and removes */
    public static function all(): array
    {
        return [self::MANAGE_SHOP];
    }
}
