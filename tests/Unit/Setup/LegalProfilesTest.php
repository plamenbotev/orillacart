<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Setup;

use OrillaCart\Setup\Domain\LegalProfiles;
use PHPUnit\Framework\TestCase;

final class LegalProfilesTest extends TestCase
{
    public function testConsumersInTheEuTheUkAndNorwayMayWithdrawForFourteenDays(): void
    {
        foreach (['DE', 'FR', 'BG', 'GB', 'NO'] as $country) {
            $profile = LegalProfiles::of($country);
            self::assertSame(14, $profile->withdrawalDays, $country);
            self::assertTrue($profile->consumerCheckout, $country);
            self::assertTrue($profile->legalNotice, $country);
        }
        self::assertNull(LegalProfiles::of('US')->withdrawalDays);
        self::assertFalse(LegalProfiles::of('US')->consumerCheckout);
    }

    public function testGermanyAndAustriaCallTheSellersPageAnImpressum(): void
    {
        self::assertSame('Impressum', LegalProfiles::of('DE')->legalNoticeTitle);
        self::assertSame('Impressum', LegalProfiles::of('AT')->legalNoticeTitle);
        self::assertSame('Legal notice', LegalProfiles::of('FR')->legalNoticeTitle);
        self::assertTrue(LegalProfiles::of('CH')->legalNotice, 'Switzerland names the seller too, without the EU’s withdrawal');
        self::assertNull(LegalProfiles::of('CH')->withdrawalDays);
    }

    public function testEInvoicesThroughANationalSystemAreNotMetByTheShopsOwn(): void
    {
        self::assertTrue(LegalProfiles::of('BE')->eInvoices?->met, 'Peppol UBL');
        self::assertFalse(LegalProfiles::of('IT')->eInvoices?->met, 'SdI');
        self::assertFalse(LegalProfiles::of('PL')->eInvoices?->met, 'KSeF');
        self::assertNull(LegalProfiles::of('SE')->eInvoices, 'no duty');
    }
}
