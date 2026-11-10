<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shared\WordPress;

use OrillaCart\Shared\WordPress\OutboundRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What the shop calls out to: public addresses only, in every form an address can hide in. */
final class OutboundRequestsTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function addresses(): iterable
    {
        yield 'loopback' => ['127.0.0.1', false];
        yield 'private' => ['10.1.2.3', false];
        yield 'metadata' => ['169.254.169.254', false];
        yield 'shared' => ['100.64.0.1', false];
        yield 'unspecified' => ['0.0.0.0', false];
        yield 'ipv6 loopback' => ['::1', false];
        yield 'mapped loopback' => ['::ffff:127.0.0.1', false];
        yield 'unique local' => ['fd00::1', false];
        yield 'link local' => ['fe80::1', false];
        yield 'nat64 loopback' => ['64:ff9b::7f00:1', false];
        yield '6to4' => ['2002:7f00:1::1', false];
        yield 'site local' => ['fec0::1', false];
        yield 'multicast v6' => ['ff02::1', false];
        yield 'multicast v4' => ['224.0.0.1', false];
        yield '6to4 relay' => ['192.88.99.1', false];
        yield 'ipv4-compatible loopback' => ['::7f00:1', false];
        yield 'teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2', false];
        yield 'unspecified v6' => ['::', false];
        yield 'not an address' => ['example.com', false];
        yield 'public v4' => ['8.8.8.8', true];
        yield 'public v6' => ['2001:4860:4860::8888', true];
    }

    #[DataProvider('addresses')]
    public function testOnlyPublicAddressesAreCalled(string $address, bool $public): void
    {
        self::assertSame($public, OutboundRequests::isPublic($address));
    }

    public function testAnAddressWrittenAsTheHostIsCheckedAsIs(): void
    {
        self::assertSame(['8.8.8.8'], OutboundRequests::publicAddresses('8.8.8.8'));
        $this->expectException(\InvalidArgumentException::class);
        OutboundRequests::publicAddresses('[::1]');
    }

    public function testTheAddressIsWrittenAsCurlReadsIt(): void
    {
        self::assertSame('http://example.com/hook?a=1', OutboundRequests::normalise('HTTP://Example.COM/hook?a=1#part'));
        self::assertSame('https://example.com:8443/', OutboundRequests::normalise(' https://EXAMPLE.com:8443/ '));
        self::assertSame('https://example.com', OutboundRequests::normalise('https://example.com'));
        self::assertNull(OutboundRequests::normalise('ftp://example.com/'));
        self::assertNull(OutboundRequests::normalise('javascript:alert(1)'));
        self::assertNull(OutboundRequests::normalise('https:///path'));
    }

    /** @return iterable<string, array{string}> */
    public static function hostsReadTwoWays(): iterable
    {
        yield 'an escaped hyphen' => ['http://a%2Db.evil.example/'];
        yield 'an escaped address' => ['http://%31%32%37.0.0.1/'];
        yield 'an escaped letter' => ['http://exa%6dple.com/'];
        yield 'full-width letters' => ["http://\u{FF45}xample.com/"];
        yield 'another script' => ['http://пример.example/'];
        yield 'a full-width dot' => ["http://127\u{3002}0.0.1/"];
        yield 'a hex address' => ['http://0x7f.1/'];
        yield 'a hex number' => ['http://0x7f000001/'];
        yield 'a decimal address' => ['http://2130706433/'];
        yield 'a short address' => ['http://127.1/'];
        yield 'an octal address' => ['http://0177.0.0.1/'];
        yield 'a name ending in a number' => ['http://example.123/'];
        yield 'an underscore' => ['http://a_b.example.com/'];
        yield 'a trailing dot' => ['http://example.com./'];
        yield 'an empty label' => ['http://a..example.com/'];
        yield 'a label that starts with a hyphen' => ['http://-a.example.com/'];
        yield 'a tab in the host' => ["http://exa\tmple.com/"];
        yield 'a line break in the path' => ["http://example.com/a\r\nHost: other"];
        yield 'a NUL' => ["http://example.com/\0x"];
        yield 'a backslash' => ['http://example.com\\@evil.example/'];
        yield 'a backslash in the path' => ['http://example.com/a\\b'];
        yield 'brackets around a name' => ['http://[example.com]/'];
        yield 'brackets around an IPv4 address' => ['http://[127.0.0.1]/'];
        yield 'an IPv6 zone' => ['http://[fe80::1%25eth0]/'];
        yield 'a second at sign' => ['http://a@b@example.com/'];
        yield 'a slash in the user' => ['http://a%2fb:c/d@example.com/'];
    }

    #[DataProvider('hostsReadTwoWays')]
    public function testAHostThatCouldBeReadTwoWaysDoesNotGo(string $url): void
    {
        self::assertNull(OutboundRequests::normalise($url));
    }

    public function testPlainHostsGo(): void
    {
        self::assertSame('https://xn--e1afmkfd.example/', OutboundRequests::normalise('https://XN--e1afmkfd.example/'), 'punycode is letters, digits and hyphens');
        self::assertSame('http://93.184.215.14/x', OutboundRequests::normalise('http://93.184.215.14/x'));
        self::assertSame('http://[2606:2800:21f:cb07::1]:8080/x', OutboundRequests::normalise('http://[2606:2800:21F:CB07::1]:8080/x'));
        self::assertSame('https://user:p%40ss@a-b.example.com/x%20y?q=%2F', OutboundRequests::normalise('https://user:p%40ss@a-b.example.com/x%20y?q=%2F'));
        self::assertSame('http://localhost:9200/', OutboundRequests::normalise('http://localhost:9200/'));
        self::assertSame('https://1password.example/', OutboundRequests::normalise('https://1password.example/'));
    }

    public function testTheHostCurlWasGivenIsTheOneChecked(): void
    {
        self::assertTrue(OutboundRequests::sameHost('http://a-b.example.com/hook?x=1', 'http://A-B.example.com:80/hook'));
        self::assertTrue(OutboundRequests::sameHost('http://[2606:2800::1]/', 'http://[2606:2800::1]:80/'));
        self::assertFalse(OutboundRequests::sameHost('http://a%2Db.example.com/', 'http://a-b.example.com/'));
        self::assertFalse(OutboundRequests::sameHost('http://example.com/', 'http://example.org/'));
        self::assertFalse(OutboundRequests::sameHost('http://example.com/', ''));
    }

    public function testTheHostIsPinnedForThePortCurlTakes(): void
    {
        self::assertSame(['example.com:80:93.184.215.14', 'example.com:443:93.184.215.14'], OutboundRequests::pins('http://example.com/hook', ['93.184.215.14']), 'no port written: both, whichever the scheme makes it');
        self::assertSame(['example.com:9200:93.184.215.14,[2606:2800:21f:cb07:6820:80da:af6b:8b2c]'], OutboundRequests::pins('https://example.com:9200/', ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']));
        self::assertSame(['::1:443:x'], \array_slice(OutboundRequests::pins('https://[::1]/', ['x']), 1), 'an address as the host, without its brackets');
    }
}
