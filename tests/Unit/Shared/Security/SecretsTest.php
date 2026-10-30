<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shared\Security;

use OrillaCart\Shared\Security\Secrets;
use OrillaCart\Shared\Security\Signatures;
use PHPUnit\Framework\TestCase;

final class SecretsTest extends TestCase
{
    public function testASealedSecretOpensOnlyWithTheSitesKey(): void
    {
        $secrets = new Secrets(new Signatures('the site salt'));

        $sealed = $secrets->seal('client-secret-123');

        self::assertTrue(Secrets::isSealed($sealed));
        self::assertStringNotContainsString('client-secret-123', $sealed);
        self::assertNotSame($sealed, $secrets->seal('client-secret-123'), 'a new nonce each time');
        self::assertSame('client-secret-123', $secrets->open($sealed));
        self::assertNull(new Secrets(new Signatures('another salt'))->open($sealed), 'the salts changed');
        self::assertNull($secrets->open(substr($sealed, 0, -4) . 'AAAA'), 'tampered with');
        self::assertSame('plain', $secrets->open('plain'), 'never sealed');
        self::assertSame('', $secrets->seal(''));
    }

    public function testWhatAnEarlierKeySealedOpensUntilSealedAgain(): void
    {
        $earlier = new Secrets(new Signatures('the salt alone'));
        $secrets = new Secrets(new Signatures('the salt alone|1'), [new Signatures('the salt alone')]);
        $sealedBefore = $earlier->seal('client-secret-123');

        self::assertSame('client-secret-123', $secrets->open($sealedBefore), 'sealed before the site took part in the key');
        self::assertFalse($secrets->sealedWithCurrentKey($sealedBefore));

        $sealedAgain = $secrets->seal('client-secret-123');

        self::assertTrue($secrets->sealedWithCurrentKey($sealedAgain));
        self::assertSame('client-secret-123', $secrets->open($sealedAgain));
        self::assertNull($earlier->open($sealedAgain), 'the earlier key alone no longer opens it');
        self::assertNull($secrets->open(new Secrets(new Signatures('a salt the site never had'))->seal('x')), 'no key the site had');
        self::assertFalse($secrets->sealedWithCurrentKey('plain'), 'never sealed: to seal');
        self::assertFalse($secrets->sealedWithCurrentKey(substr($sealedAgain, 0, -4) . 'AAAA'), 'tampered with');
    }

    public function testEachPurposeHasItsOwnKey(): void
    {
        $signatures = new Signatures('the site salt');

        self::assertNotSame($signatures->key('secrets'), $signatures->key('receipt'));
        self::assertSame(32, \strlen($signatures->key('secrets')));
        self::assertTrue($signatures->verify('receipt', '7-1', $signatures->sign('receipt', '7-1')));
        self::assertFalse($signatures->verify('payment-return', '7-1', $signatures->sign('receipt', '7-1')));
    }
}
