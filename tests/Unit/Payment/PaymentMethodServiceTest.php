<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Payment;

use OrillaCart\Payment\Application\Dto\PaymentMethodView;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Domain\PaymentMethodNotFound;
use OrillaCart\Payment\Domain\PaymentRuleViolation;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class PaymentMethodServiceTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
    }

    public function testEveryOldPaymentMethodIsThereAndOffUntilTheShopTurnsItOn(): void
    {
        $methods = $this->shop->paymentMethods->methods();

        self::assertSame(
            [['cash_on_delivery', 'Cash on delivery', false, true], ['bank_transfer', 'Bank transfer', false, true], ['cheque', 'Cheque', false, true], ['paypal', 'PayPal', false, false]],
            array_map(static fn(PaymentMethodView $method): array => [$method->gateway, $method->name, $method->active, $method->ready], $methods),
        );
        self::assertSame(['client_id', 'client_secret', 'sandbox', 'webhook_id'], array_map(static fn($field): string => $field->key, $methods[3]->fields));
        self::assertSame('https://shop.test/payments/paypal/webhook', $methods[3]->notificationUrl);
    }

    public function testSecretsAreKeptButNeverShown(): void
    {
        $saved = $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, settings: ['client_id' => 'abc', 'client_secret' => 's3cret', 'sandbox' => true]));
        self::assertSame(['client_id' => 'abc', 'client_secret' => '', 'sandbox' => true, 'webhook_id' => ''], $saved->settings);
        self::assertSame(['client_secret'], $saved->secretsSet);
        self::assertTrue($saved->ready);

        // Saving again without the secret keeps it.
        $again = $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, settings: ['client_id' => 'abc', 'client_secret' => '', 'sandbox' => false]));
        self::assertSame(['client_secret'], $again->secretsSet);
        self::assertStringNotContainsString('s3cret', (string) json_encode($again));
    }

    public function testPayPalCannotBeTurnedOnWithoutItsKeys(): void
    {
        try {
            $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, settings: ['client_id' => 'abc']));
            self::fail('no secret');
        } catch (PaymentRuleViolation $e) {
            self::assertSame('orillacart_payment_method_incomplete', $e->errorCode);
        }

        // Off, it can be saved half set up.
        self::assertFalse($this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', false, settings: ['client_id' => 'abc']))->active);
    }

    public function testOnlyTheGatewaysOwnSettingsAreAccepted(): void
    {
        try {
            $this->shop->paymentMethods->update('cheque', new SavePaymentMethod('Cheque', settings: ['iban' => 'BG00']));
            self::fail('cheques have no settings');
        } catch (PaymentRuleViolation $e) {
            self::assertSame('orillacart_unknown_payment_setting', $e->errorCode);
        }

        $this->expectException(PaymentMethodNotFound::class);
        $this->shop->paymentMethods->update('bitcoin', new SavePaymentMethod('Bitcoin'));
    }

    public function testPayPalIsNotOfferedInCurrenciesItDoesNotTake(): void
    {
        $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, settings: ['client_id' => 'abc', 'client_secret' => 's']));
        $this->shop->settings->save(new \OrillaCart\Settings\Domain\StoreSettings(new \OrillaCart\Shared\Domain\Currency('BGN'), 2, \OrillaCart\Settings\Domain\WeightUnit::Kilogram, \OrillaCart\Settings\Domain\DimensionUnit::Centimetre));

        self::assertFalse($this->shop->paymentMethods->show('paypal')->ready);
    }
}
