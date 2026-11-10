<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

/**
 * A request out of the shop to an address someone other than the site's administrator chose: an extension's
 * declared host, a webhook. It goes only to a public address: never to the server itself, the private network or
 * a cloud's metadata service (169.254.169.254). The host is resolved once and the connection pinned to what it
 * resolved to, so a name cannot answer a public address to the check and a private one to the connection. It
 * follows a redirect only when the caller allows, and checks its address as the first one. A site in development may call its
 * own local names (localhost, *.test, *.local) when the caller allows it. The checking and pinning happen in the
 * request's own filters (Integrations' PublicOnlyRequests), for the requests sent through here.
 *
 * The address is written again before it goes (scheme and host in lowercase, no fragment), and only a host written
 * plainly goes at all: letters, digits and hyphens in dotted labels, an IPv4 address in its four numbers, or an
 * IPv6 address in brackets. WordPress's HTTP library and curl both read a host again before connecting (percent
 * escapes decoded, other scripts turned to punycode), so a host written any other way would be checked under one
 * name and called under another. The guard also compares the host curl was given with the one it checked. The pin holds for curl alone, connecting itself: the request is refused when WordPress would send it
 * another way (no curl) or through a proxy (WP_PROXY_HOST), which resolves the name itself. A request started here
 * whose guard argument another plugin's filter took away is refused too, not sent unguarded.
 */
final class OutboundRequests
{
    /** The argument that asks the shop's request guard (PublicOnlyRequests) to check and pin the address. */
    public const string PUBLIC_ONLY = 'orillacart_public_only';

    /** @var array<string, int> the addresses being sent to through here now, as written for WordPress */
    private static array $sending = [];

    /** @var array<string, int> of those, the ones that asked for the guard */
    private static array $guarded = [];

    private const string LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /**
     * As wp_remote_request(): the response, or a WP_Error, also when the address is not one to call. Redirects are
     * followed only when $redirects allows, each one checked as the first address was (a GET from then on, as
     * browsers do for 301, 302 and 303).
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>|\WP_Error
     */
    public static function send(string $url, array $args, bool $allowLocal = false, int $redirects = 0): array|\WP_Error
    {
        $args['redirection'] = 0;
        for ($hop = 0; ; ++$hop) {
            $written = self::normalise($url);
            if ($written === null) {
                return new \WP_Error('orillacart_unsafe_address', \sprintf('%s is not a web address.', $url));
            }
            $url = $written;
            $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
            $public = !($allowLocal && self::local($host) && self::development());
            $args[self::PUBLIC_ONLY] = $public;
            self::$sending[$url] = (self::$sending[$url] ?? 0) + 1;
            self::$guarded[$url] = (self::$guarded[$url] ?? 0) + ($public ? 1 : 0);
            try {
                // @phpstan-ignore argument.type (WordPress passes keys it does not know on to its filters, as this one is)
                $response = wp_remote_request($url, $args);
            } finally {
                self::$guarded[$url] -= $public ? 1 : 0;
                if (--self::$sending[$url] <= 0) {
                    unset(self::$sending[$url], self::$guarded[$url]);
                }
            }
            $status = \is_array($response) ? (int) wp_remote_retrieve_response_code($response) : 0;
            $location = \is_array($response) ? wp_remote_retrieve_header($response, 'location') : '';
            if ($hop >= $redirects || $status < 300 || $status > 399 || !\is_string($location) || $location === '') {
                return $response;
            }
            $next = \WP_Http::make_absolute_url($location, $url);
            if (!\in_array(strtolower((string) parse_url($next, \PHP_URL_SCHEME)), ['http', 'https'], true)) {
                return new \WP_Error('orillacart_unsafe_address', \sprintf('%s redirected to %s, which is not a web address.', $url, $next));
            }
            if ($status !== 307 && $status !== 308) {
                $args['method'] = 'GET';
                unset($args['body']);
            }
            $url = $next;
        }
    }

    /** Whether a request to the address was started here and is under way. */
    public static function sending(string $url): bool
    {
        return isset(self::$sending[$url]);
    }

    /**
     * What a request to the address under way asked of the guard: true to check and pin it, false for a local
     * name in development; null when no request to it was started here. The guard holds the request to this, so
     * a filter that changes the argument on the way is noticed as one that removes it.
     */
    public static function expected(string $url): ?bool
    {
        return isset(self::$sending[$url]) ? (self::$guarded[$url] ?? 0) > 0 : null;
    }

    /**
     * The address as curl reads it: an http or https scheme and the host in lowercase, the port when one is given,
     * the path and query as they are, no fragment; null for one that is not a web address.
     */
    public static function normalise(string $url): ?string
    {
        $url = trim($url);
        // Nothing a reader could take two ways: no spaces, control characters or backslashes anywhere.
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = self::host($parts['host']);
        if (!\in_array($scheme, ['http', 'https'], true) || $host === null) {
            return null;
        }
        foreach ([$parts['user'] ?? '', $parts['pass'] ?? ''] as $credential) {
            if (preg_match('/^[A-Za-z0-9._~%!$&\'()*+,;=-]*$/', $credential) !== 1) {
                return null;
            }
        }
        $user = isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $user . $host . $port . ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * The host in lowercase when it is written plainly, null otherwise: dotted labels of letters, digits and
     * hyphens (punycode for other scripts), an IPv4 address as four numbers, or an IPv6 address in brackets. A
     * percent escape, another script's letters, an underscore, or a number that is an address in another form
     * (2130706433, 0x7f.1, 127.1) is not: what reads those again may not read them as the guard did.
     */
    public static function host(string $host): ?string
    {
        $host = strtolower($host);
        if (str_starts_with($host, '[')) {
            return str_ends_with($host, ']') && filter_var(substr($host, 1, -1), \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) !== false ? $host : null;
        }
        if (\strlen($host) > 253 || preg_match('/^' . self::LABEL . '(?:\.' . self::LABEL . ')*$/', $host) !== 1) {
            return null;
        }
        $last = substr($host, (int) strrpos('.' . $host, '.'));
        // A name that ends in a number is an address to whatever connects: only the four-number form is taken.
        if (preg_match('/^(?:[0-9]+|0x[0-9a-f]*)$/', $last) === 1 && filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) === false) {
            return null;
        }

        return $host;
    }

    /** Whether the address curl was given names the host that was checked, port and all else aside. */
    public static function sameHost(string $checked, string $given): bool
    {
        $host = static fn(string $url): string => trim(strtolower((string) parse_url($url, \PHP_URL_HOST)), '[]');

        return $host($checked) !== '' && $host($checked) === $host($given);
    }

    /**
     * What pins the address's host to the addresses it was checked at, for CURLOPT_RESOLVE: for the port the
     * address names, and for both web ports when it names none, whichever curl takes.
     *
     * @param list<string> $addresses
     *
     * @return list<string>
     */
    public static function pins(string $url, array $addresses): array
    {
        $host = trim(strtolower((string) parse_url($url, \PHP_URL_HOST)), '[]');
        $port = parse_url($url, \PHP_URL_PORT);
        $ports = \is_int($port) ? [$port] : [80, 443];
        $written = implode(',', array_map(static fn(string $address): string => str_contains($address, ':') ? '[' . $address . ']' : $address, $addresses));

        return array_map(static fn(int $port): string => \sprintf('%s:%d:%s', $host, $port, $written), $ports);
    }

    /**
     * Every address the host resolves to, all public.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException when it resolves to none, or to one that is not public
     */
    public static function publicAddresses(string $host): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            $found = gethostbynamel($host);
            $addresses = $found === false ? [] : $found;
            $records = @dns_get_record($host, \DNS_AAAA);
            foreach ($records === false ? [] : $records as $record) {
                if (\is_string($record['ipv6'] ?? null)) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }
        if ($addresses === []) {
            throw new \InvalidArgumentException(\sprintf('%s has no address.', $host));
        }
        foreach ($addresses as $address) {
            if (!self::isPublic($address)) {
                throw new \InvalidArgumentException(\sprintf('%s is not a public address (%s): the shop calls only public ones.', $host, $address));
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * An address of the public internet. IPv6: only global unicast (2000::/3), which leaves out loopback,
     * link-local, site-local, unique local, multicast and every form that carries an IPv4 address in its low bits
     * (IPv4-mapped, IPv4-compatible, NAT64), and of it not 6to4 (2002::/16) or Teredo (2001::/32), which carry one
     * higher up. IPv4: not private, loopback, link-local, shared (100.64/10), reserved, multicast (224/4) or the
     * 6to4 relay (192.88.99/24). PHP's ranges are asked on top of both.
     */
    public static function isPublic(string $address): bool
    {
        $binary = @inet_pton($address);
        if ($binary === false) {
            return false;
        }
        if (\strlen($binary) === 16) {
            if ((\ord($binary[0]) & 0xE0) !== 0x20 || str_starts_with($binary, "\x20\x02") || str_starts_with($binary, "\x20\x01\x00\x00")) {
                return false;
            }
        } elseif ((\ord($binary[0]) & 0xF0) === 0xE0 || str_starts_with($binary, "\xC0\x58\x63")) {
            return false;
        }

        return filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_GLOBAL_RANGE | \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** A name that is the server itself or the local network's, called over plain http in development. */
    public static function local(string $host): bool
    {
        return \in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test') || str_ends_with($host, '.local');
    }

    /** Whether the site says it is a local or development copy (WP_ENVIRONMENT_TYPE). */
    public static function development(): bool
    {
        return \in_array(wp_get_environment_type(), ['local', 'development'], true);
    }
}
