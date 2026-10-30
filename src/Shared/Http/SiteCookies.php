<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http;

/**
 * Where the shop's cookies are set: at the site's own path (and COOKIE_DOMAIN where one is set), so two shops of
 * a network in folders of one domain each keep cookies of their own instead of overwriting each other's. On a
 * single site that is WordPress's COOKIEPATH; on a network COOKIEPATH is the network's path (one login for every
 * site), which would give every shop of the network one cart, so there the site's own folder is used.
 */
final class SiteCookies
{
    /** The path the shop's cookies are set at: the site's own; the whole host without one. */
    public static function path(): string
    {
        $path = self::sitePath() ?? (\defined('COOKIEPATH') ? \constant('COOKIEPATH') : '');

        return \is_string($path) && $path !== '' ? $path : '/';
    }

    /** On a network, the current site's folder ("/shop-b/"); null on a single site, or where it is not known. */
    private static function sitePath(): ?string
    {
        if (!\function_exists('is_multisite') || !is_multisite() || !\function_exists('get_site')) {
            return null;
        }
        $path = get_site()?->path;

        return \is_string($path) && $path !== '' ? $path : null;
    }

    /** The domain the shop's cookies are set for, when the site sets one (COOKIE_DOMAIN); '' for the host alone. */
    public static function domain(): string
    {
        $domain = \defined('COOKIE_DOMAIN') ? \constant('COOKIE_DOMAIN') : '';

        return \is_string($domain) ? $domain : '';
    }
}
