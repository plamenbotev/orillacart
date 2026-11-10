<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Security;

use OrillaCart\Shared\Security\Signatures;

/**
 * Who sends a request, as the shop may keep it: a key made with the site's secret from the caller as the limits
 * count callers, which tells two of them apart and nobody's address.
 */
final readonly class CallerKeys
{
    public function __construct(private Signatures $signatures) {}

    /**
     * @param string $caller "ip:203.0.113.7"
     *
     * @return string 32 hexadecimal characters
     */
    public function of(string $caller): string
    {
        return $this->signatures->sign('caller', self::network($caller));
    }

    /**
     * A caller as the limits count it. `ip:` an address: an IPv6 one by its /64, since one connection is given a
     * whole /64 to pick addresses from and counting each address would give one caller billions of tries; an
     * IPv4 one as it is, also when it comes written as IPv6 (`::ffff:a.b.c.d`, or the NAT64 prefix `64:ff9b::/96`),
     * which would otherwise put every IPv4 caller of a dual-stack server in one /64. `net:` an address by the
     * network around it, for limits on what a whole provider's customer could fill: an IPv6 /48 (what a site is
     * given), an IPv4 /24. Callers that are no address, as they are.
     */
    public static function network(string $caller): string
    {
        $wide = str_starts_with($caller, 'net:');
        if (!$wide && !str_starts_with($caller, 'ip:')) {
            return $caller;
        }
        $address = substr($caller, $wide ? 4 : 3);
        $binary = filter_var($address, \FILTER_VALIDATE_IP) === false ? false : @inet_pton($address);
        if ($binary === false) {
            return $caller;
        }
        if (\strlen($binary) === 16 && (str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff") || str_starts_with($binary, "\x00\x64\xff\x9b" . str_repeat("\0", 8)))) {
            $binary = substr($binary, 12);
        }
        if (\strlen($binary) === 4) {
            $ipv4 = (string) inet_ntop($binary);

            return $wide ? 'net:' . (string) inet_ntop(substr($binary, 0, 3) . "\0") . '/24' : 'ip:' . $ipv4;
        }

        return $wide ? 'net:' . bin2hex(substr($binary, 0, 6)) . '::/48' : 'ip:' . bin2hex(substr($binary, 0, 8)) . '::/64';
    }
}
