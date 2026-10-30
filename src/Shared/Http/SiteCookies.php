<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http;

/**
 * Where the shop's cookies are set: at the site's own path, for the host alone, so two shops of a network in
 * folders of one domain each keep cookies of their own instead of overwriting each other's. On a single site that
 * is WordPress's COOKIEPATH; on a network COOKIEPATH is the network's path (one login for every site), which would
 * give every shop of the network one cart, so there the site's own folder is used. COOKIE_DOMAIN is never given:
 * a network that shares it across subdomains or mapped domains would have every shop read the others' cart.
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

    /**
     * Every place a shop cookie can be at in the browser, the current one first: the site's own path for the host
     * alone, and those earlier builds used, the whole host and COOKIE_DOMAIN. Forgetting a cookie expires it at
     * each, so one set before does not bring back what was forgotten.
     *
     * @return non-empty-list<array{path: string, domain: string}>
     */
    public static function everywhere(): array
    {
        $domain = \defined('COOKIE_DOMAIN') ? \constant('COOKIE_DOMAIN') : '';
        $domains = array_values(array_unique(['', \is_string($domain) ? $domain : '']));
        $paths = array_values(array_unique([self::path(), '/']));
        $places = [];
        foreach ($domains as $at) {
            foreach ($paths as $path) {
                $places[] = ['path' => $path, 'domain' => $at];
            }
        }

        return $places;
    }
}
