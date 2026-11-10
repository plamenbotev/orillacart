<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shared;

use OrillaCart\Shared\Domain\EmailAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailAddressTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function addresses(): iterable
    {
        yield 'lowercased' => [' Ada@Example.COM ', 'ada@example.com'];
        yield 'a +tag on any domain' => ['ada+shop@example.com', 'ada@example.com'];
        yield 'Gmail ignores dots' => ['a.d.a+1@gmail.com', 'ada@gmail.com'];
        yield 'Googlemail is Gmail' => ['A.da@googlemail.com', 'ada@gmail.com'];
        yield 'dots elsewhere matter' => ['a.da@example.com', 'a.da@example.com'];
        yield 'a + first is the address' => ['+ada@example.com', '+ada@example.com'];
        yield 'nothing is nothing' => ['', ''];
    }

    #[DataProvider('addresses')]
    public function testAnAddressIsKeyedByWhoItReaches(string $email, string $key): void
    {
        self::assertSame($key, EmailAddress::key($email));
    }
}
