<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Security;

/**
 * The visitor's address, for the rate limits. Behind a proxy or a CDN (Cloudflare, a load balancer) every request
 * comes from the proxy's address, so the shop would count all its visitors as one: when REMOTE_ADDR is a proxy the
 * site trusts (ORILLACART_TRUSTED_PROXIES, comma-separated addresses or ranges such as 10.0.0.0/8, or the filter
 * orillacart_trusted_proxies), the address is the last one in X-Forwarded-For that is not one of its proxies, or
 * CF-Connecting-IP. A header from anyone else is ignored: a visitor cannot choose their own address.
 */
final class ClientAddress
{
    /**
     * @param array<array-key, mixed> $server  $_SERVER, or a request's server params
     * @param list<string>|null       $proxies the trusted proxies; null for the site's
     */
    public static function of(array $server, ?array $proxies = null): string
    {
        $remote = \is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';
        $proxies ??= self::trustedProxies();
        if ($remote === '' || $proxies === [] || !self::isIn($remote, $proxies)) {
            return $remote === '' ? 'unknown' : $remote;
        }
        $connecting = \is_string($server['HTTP_CF_CONNECTING_IP'] ?? null) ? trim($server['HTTP_CF_CONNECTING_IP']) : '';
        if (filter_var($connecting, \FILTER_VALIDATE_IP) !== false) {
            return $connecting;
        }
        $forwarded = \is_string($server['HTTP_X_FORWARDED_FOR'] ?? null) ? array_reverse(array_map(trim(...), explode(',', $server['HTTP_X_FORWARDED_FOR']))) : [];
        foreach ($forwarded as $hop) {
            if (filter_var($hop, \FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (!self::isIn($hop, $proxies)) {
                return $hop;
            }
        }

        return $remote;
    }

    /**
     * Whether the request came through a proxy the site does not trust yet: the rate limits then count the proxy.
     *
     * @param array<array-key, mixed> $server
     */
    public static function proxiedUntrusted(array $server): bool
    {
        $remote = \is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';

        return (isset($server['HTTP_X_FORWARDED_FOR']) || isset($server['HTTP_CF_CONNECTING_IP'])) && !self::isIn($remote, self::trustedProxies());
    }

    /** @return list<string> */
    private static function trustedProxies(): array
    {
        $configured = \defined('ORILLACART_TRUSTED_PROXIES') ? \constant('ORILLACART_TRUSTED_PROXIES') : '';
        $proxies = \is_string($configured) ? array_filter(array_map(trim(...), explode(',', $configured)), static fn(string $proxy): bool => $proxy !== '') : [];
        $filtered = \function_exists('apply_filters') ? apply_filters('orillacart_trusted_proxies', array_values($proxies)) : $proxies;

        return \is_array($filtered) ? array_values(array_filter($filtered, \is_string(...))) : [];
    }

    /**
     * @param list<string> $ranges addresses, or ranges in CIDR notation
     */
    private static function isIn(string $address, array $ranges): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        foreach ($ranges as $range) {
            $parts = explode('/', $range, 2);
            $base = $parts[0];
            $bits = $parts[1] ?? null;
            $network = @inet_pton($base);
            if ($network === false || \strlen($network) !== \strlen($packed)) {
                continue;
            }
            $bits = $bits === null || !ctype_digit($bits) ? \strlen($packed) * 8 : min((int) $bits, \strlen($packed) * 8);
            $bytes = intdiv($bits, 8);
            if (substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0 || ((\ord($packed[$bytes]) ^ \ord($network[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }
}
