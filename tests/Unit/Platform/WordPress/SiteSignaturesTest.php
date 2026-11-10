<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\WordPress;

use OrillaCart\Ordering\Application\ReceiptLinks;
use OrillaCart\Platform\WordPress\Security\SiteSignatures;
use OrillaCart\Shared\Security\Secrets;
use OrillaCart\Shared\Security\Signatures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The sites of a network share the salts; each site signs with a key of its own.
 */
final class SiteSignaturesTest extends TestCase
{
    public function testTwoSitesOfOneNetworkDoNotTakeEachOthersTokens(): void
    {
        $salt = 'the salt every site of the network shares';
        $shopA = new Signatures(SiteSignatures::secret($salt, 1));
        $shopB = new Signatures(SiteSignatures::secret($salt, 2));

        $signature = $shopA->sign('receipt', '1001-20000');

        self::assertTrue($shopA->verify('receipt', '1001-20000', $signature));
        self::assertFalse($shopB->verify('receipt', '1001-20000', $signature), 'B has an order 1001 of its own; A\'s token must not show it');
        self::assertNotSame($shopA->key('secrets'), $shopB->key('secrets'));
    }

    public function testAReceiptLinkOfOneShopIsNoReceiptAtAnother(): void
    {
        $clock = new MockClock('2026-10-30 12:00:00');
        $salt = 'the salt every site of the network shares';
        $shopA = new ReceiptLinks(new Signatures(SiteSignatures::secret($salt, 1)), $clock);
        $shopB = new ReceiptLinks(new Signatures(SiteSignatures::secret($salt, 2)), $clock);

        $token = $shopA->issue(1001);

        self::assertSame([1001, false], $shopA->read($token));
        self::assertNull($shopB->read($token));
    }

    public function testTheSecretIsTheSaltAndTheSite(): void
    {
        self::assertSame('salt|1', SiteSignatures::secret('salt', 1));
        self::assertNotSame(SiteSignatures::secret('salt', 1), SiteSignatures::secret('salt', 12));
        self::assertNotSame(SiteSignatures::secret('salt|1', 2), SiteSignatures::secret('salt', 1));
    }

    public function testTheEarlierSecretIsTheSaltAloneAndStillOpensWhatItSealed(): void
    {
        $salt = 'the salt every site of the network shares';

        self::assertSame([$salt], SiteSignatures::earlierSecrets($salt));
        self::assertNotContains(SiteSignatures::secret($salt, 1), SiteSignatures::earlierSecrets($salt));

        $before = new Secrets(new Signatures($salt));
        $now = new Secrets(new Signatures(SiteSignatures::secret($salt, 1)), array_map(static fn(string $secret): Signatures => new Signatures($secret), SiteSignatures::earlierSecrets($salt)));

        self::assertSame('paypal-secret', $now->open($before->seal('paypal-secret')));
        self::assertFalse($now->sealedWithCurrentKey($before->seal('paypal-secret')));
        self::assertTrue($now->sealedWithCurrentKey($now->seal('paypal-secret')));
    }

    public function testAnOwnSecretsKeySealsAndWhatTheSaltsSealedStillOpens(): void
    {
        $salt = static fn(): string => 'salt kept in the database';
        $site = static fn(): int => 3;
        $salted = SiteSignatures::secrets(null, $salt, $site);
        $own = SiteSignatures::secrets('a long random phrase', $salt, $site);
        $sealed = $salted->seal('stripe-key');

        self::assertSame('stripe-key', $own->open($sealed), 'sealed with the salts before the key was set');
        self::assertFalse($own->sealedWithCurrentKey($sealed), 'doctor --fix seals it again');
        self::assertNull($salted->open($own->seal('stripe-key')), 'the salts alone do not open what the own key sealed');
        self::assertNull(SiteSignatures::secrets('a long random phrase', $salt, static fn(): int => 4)->open($own->seal('stripe-key')), 'another site of the network');
    }
}
