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
 * The address is written again before it goes (scheme and host in lowercase, no fragment), so the guard reads it as
 * curl will. The pin holds for curl alone, connecting itself: the request is refused when WordPress would send it
 * another way (no curl) or through a proxy (WP_PROXY_HOST), which resolves the name itself. A request started here
 * whose guard argument another plugin's filter took away is refused too, not sent unguarded.
 */
final class OutboundRequests
{
    /** The argument that asks the shop's request guard (PublicOnlyRequests) to check and pin the address. */
    public const string PUBLIC_ONLY = 'orillacart_public_only';

    /** @var array<string, int> the addresses being sent to through here now, as written for WordPress */
    private static array $sending = [];

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
            $args[self::PUBLIC_ONLY] = !($allowLocal && self::local($host) && self::development());
            self::$sending[$url] = (self::$sending[$url] ?? 0) + 1;
            try {
                // @phpstan-ignore argument.type (WordPress passes keys it does not know on to its filters, as this one is)
                $response = wp_remote_request($url, $args);
            } finally {
                if (--self::$sending[$url] <= 0) {
                    unset(self::$sending[$url]);
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
     * The address as curl reads it: an http or https scheme and the host in lowercase, the port when one is given,
     * the path and query as they are, no fragment; null for one that is not a web address.
     */
    public static function normalise(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true) || preg_match('/[\s\\\\]/', $parts['host']) === 1) {
            return null;
        }
        $user = isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $user . strtolower($parts['host']) . $port . ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
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
     * Neither private, loopback, link-local, shared (100.64/10), reserved, nor an IPv6 form of one of those: the
     * IPv4-mapped ::ffff:0:0/96 is refused by PHP's ranges, the NAT64 64:ff9b::/96 and 6to4 2002::/16 forms, which
     * carry an IPv4 address inside, here.
     */
    public static function isPublic(string $address): bool
    {
        $binary = @inet_pton($address);
        if ($binary === false) {
            return false;
        }
        if (\strlen($binary) === 16 && (str_starts_with($binary, "\x00\x64\xff\x9b") || str_starts_with($binary, "\x20\x02"))) {
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
