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
}
