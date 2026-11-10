<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Scripting;

use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\Budget;
use OrillaCart\Scripting\Application\Dto\Grant;
use OrillaCart\Scripting\Application\Host\CryptoFunctions;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Domain\ScriptError;
use PHPUnit\Framework\TestCase;

/** What a scripted gateway checks a provider's signature with. */
final class CryptoFunctionsTest extends TestCase
{
    public function testAnHmacIsThatOfTheDataWithTheKeyAsHexOrBase64(): void
    {
        self::assertSame(hash_hmac('sha256', 't=1.{"a":1}', 'whsec'), self::call('crypto.hmac', ['sha256', 't=1.{"a":1}', 'whsec']));
        self::assertSame(base64_encode(hash_hmac('sha512', 'body', 'key', true)), self::call('crypto.hmac', ['SHA512', 'body', 'key', 'base64']));
    }

    public function testOnlyStrongHashesAndAKeyAreTaken(): void
    {
        foreach ([['md5', 'body', 'key'], ['sha1', 'body', 'key'], ['sha256', 'body', ''], ['sha256', 'body', 'key', 'binary']] as $arguments) {
            try {
                self::call('crypto.hmac', $arguments);
                self::fail(implode(', ', $arguments));
            } catch (ScriptError) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEqualsComparesWholeStrings(): void
    {
        self::assertTrue(self::call('crypto.equals', ['abc', 'abc']));
        self::assertFalse(self::call('crypto.equals', ['abc', 'abd']));
        self::assertFalse(self::call('crypto.equals', ['abc', 'ab']));
    }

    /** @param list<mixed> $arguments */
    private static function call(string $function, array $arguments): mixed
    {
        return new Context(HostCatalog::of(new CryptoFunctions()->functions()), new Budget(), new Grant('pay', [], []))->call($function, $arguments);
    }
}
