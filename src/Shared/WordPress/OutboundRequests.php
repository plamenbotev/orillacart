<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

/**
 * A request out of the shop to an address someone other than the site's administrator chose: an extension's
 * declared host, a webhook. It goes only to a public address: never to the server itself, the private network or
 * a cloud's metadata service (169.254.169.254). The host is resolved once and the connection pinned to what it
 * resolved to, so a name cannot answer a public address to the check and a private one to the connection. It
 * follows no redirect: a redirect would be a second address nobody checked. A site in development may call its
 * own local names (localhost, *.test, *.local) when the caller allows it. The checking and pinning happen in the
 * request's own filters (Integrations' PublicOnlyRequests), for the requests sent through here.
 */
final class OutboundRequests
{
    /** The argument that asks the shop's request guard (PublicOnlyRequests) to check and pin the address. */
    public const string PUBLIC_ONLY = 'orillacart_public_only';

    /**
     * As wp_remote_request(): the response, or a WP_Error, also when the address is not one to call.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>|\WP_Error
     */
    public static function send(string $url, array $args, bool $allowLocal = false): array|\WP_Error
    {
        $args['redirection'] = 0;
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
        $args[self::PUBLIC_ONLY] = !($allowLocal && self::local($host) && self::development());

        // @phpstan-ignore argument.type (WordPress passes keys it does not know on to its filters, as this one is)
        return wp_remote_request($url, $args);
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
