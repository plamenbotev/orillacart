<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Home;

/**
 * Where a Home line or tile may lead: a Shop page with its query (`orders?status=processing`), an admin page
 * (`admin.php?page=acme`), a path of the site (`/wp-admin/edit.php`), or an http(s) URL on the site's own hosts.
 * Anything else (`javascript:`, `//host`, another site) is no link.
 */
final class HomeUrl
{
    /** A Shop page, an admin page or a path of the site, with no scheme or host and nothing a browser drops. */
    private const string RELATIVE = '#^(?:[a-z0-9_-]+(?:\.php)?(?:[?\#][^\s\x00-\x1f\x7f<>"\'`\\\\]*)?|/(?![/\\\\])[^\s\x00-\x1f\x7f<>"\'`\\\\]*)$#Di';
    private const string ABSOLUTE = '#^https?://[^/\\\\\s\x00-\x1f\x7f]#i';

    /**
     * The link as the Home screen shows it; '' for none.
     *
     * @param \Closure(string): ?string $sameSite the URL made safe when it is on one of the site's own hosts
     */
    public static function link(string $url, \Closure $sameSite): string
    {
        if ($url === '' || self::relative($url)) {
            return $url;
        }

        return self::absolute($url) ? $sameSite($url) ?? '' : '';
    }

    /** Whether the URL is a Shop page, an admin page or a path of the site. */
    public static function relative(string $url): bool
    {
        return preg_match(self::RELATIVE, $url) === 1;
    }

    /** Whether the URL is http(s) with a host: it then leads somewhere only when the host is the site's. */
    public static function absolute(string $url): bool
    {
        return preg_match(self::ABSOLUTE, $url) === 1 && preg_match('/[\s\x00-\x1f\x7f]/', $url) !== 1;
    }
}
