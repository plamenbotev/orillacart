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

    public function testTheHostIsPinnedForThePortCurlTakes(): void
    {
        self::assertSame(['example.com:80:93.184.215.14', 'example.com:443:93.184.215.14'], OutboundRequests::pins('http://example.com/hook', ['93.184.215.14']), 'no port written: both, whichever the scheme makes it');
        self::assertSame(['example.com:9200:93.184.215.14,[2606:2800:21f:cb07:6820:80da:af6b:8b2c]'], OutboundRequests::pins('https://example.com:9200/', ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']));
        self::assertSame(['::1:443:x'], \array_slice(OutboundRequests::pins('https://[::1]/', ['x']), 1), 'an address as the host, without its brackets');
    }
}
