<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Payment;

use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Translations\Domain\TranslationRuleViolation;
use PHPUnit\Framework\TestCase;

/**
 * Design 51: a payment method's name, instructions, receipt note and fee label in the site's other languages.
 */
final class PaymentTranslationsTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop('2026-10-29 12:00:00 UTC');
        $this->shop->contentLanguages->languages = [new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch')];
    }

    public function testTheShopperReadsTheMethodInTheLanguageShown(): void
    {
        $view = $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true, instructions: 'Pay to our account.', receiptNote: 'IBAN BG00', fee: '1', translations: [
            'name' => ['de_DE' => 'Überweisung'],
            'receipt_note' => ['de_DE' => 'IBAN BG00 (Verwendungszweck: Bestellnummer)'],
        ]));
        self::assertSame(['name' => ['de_DE' => 'Überweisung'], 'receipt_note' => ['de_DE' => 'IBAN BG00 (Verwendungszweck: Bestellnummer)']], $view->translations);

        self::assertSame('Bank transfer', $this->shop->paymentOptions->name('bank_transfer'));
        $this->shop->languageOfRequest->locale = 'de_DE';
        $option = $this->shop->paymentOptions->available('BG', 'EUR')[0];
        self::assertSame(['Überweisung', 'Pay to our account.'], [$option->name, $option->instructions], 'instructions without a translation stay');
        self::assertSame('IBAN BG00 (Verwendungszweck: Bestellnummer)', $this->shop->paymentOptions->receiptNote('bank_transfer'));
        self::assertSame('Überweisung', $this->shop->paymentOptions->fee('bank_transfer')?->label, 'a fee without its own label takes the name, translated');
    }

    public function testASaveWithoutTranslationsKeepsThem(): void
    {
        $this->shop->paymentMethods->update('cash_on_delivery', new SavePaymentMethod('Cash on delivery', true, translations: ['name' => ['de_DE' => 'Nachnahme']]));
        $view = $this->shop->paymentMethods->update('cash_on_delivery', new SavePaymentMethod('Cash on delivery', false));

        self::assertSame(['name' => ['de_DE' => 'Nachnahme']], $view->translations);
    }

    public function testAFieldTheMethodDoesNotHaveIsRefused(): void
    {
        $this->expectException(TranslationRuleViolation::class);

        $this->shop->paymentMethods->update('cash_on_delivery', new SavePaymentMethod('Cash on delivery', true, translations: ['settings' => ['de_DE' => 'x']]));
    }
}
