<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Payment;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\EditedItem;
use OrillaCart\Checkout\Application\Dto\EditOrderItems;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Invoicing\Domain\Invoice;
use OrillaCart\Invoicing\Domain\InvoiceKind;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Domain\Payment;
use OrillaCart\Payment\Domain\PaymentRuleViolation;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * What a refund gives back is money with tax in it: the books, the credit note and the VAT return need that tax,
 * and no refund gives back more than the customer paid.
 */
final class RefundTaxTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'Lamp');
        $this->shop->products->save(20, new SaveProduct('10', 'virtual'));
        $vat = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        $this->shop->taxGroups->addRate($vat, new SaveTaxRate('BG', null, '20', 'VAT'));
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $vat));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, settings: ['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1']));
    }

    public function testEachRefundGivesBackTheTaxOfWhatItCoversAndTheLastAllThatIsLeft(): void
    {
        $order = $this->paid(2);
        self::assertSame(['24', '4'], [$this->shop->orders->order($order)->total, $this->shop->orders->order($order)->itemsTax]);

        $this->shop->refunds->refund($order, new MakeRefund('12', [new RefundLine(0, 1)]), 7);
        $this->shop->refunds->refund($order, new MakeRefund('6', reason: 'Goodwill.'), 7);
        $this->shop->refunds->refund($order, new MakeRefund('6'), 7);

        $refunds = $this->shop->refunds->forOrder($order)->refunds;
        self::assertSame(['2', '1', '1'], array_map(static fn($refund): string => $refund->tax, $refunds), 'one lamp of two; a quarter of the order; what is left');
        self::assertSame([['name' => 'VAT', 'percent' => '20', 'amount' => '2']], $refunds[0]->taxes);
        self::assertSame('24', $this->shop->orders->order($order)->lines[0]->refundedAmount, 'the line holds its share of each refund');

        $credits = array_values(array_filter($this->shop->invoiceRecords->invoices, static fn(Invoice $invoice): bool => $invoice->kind() === InvoiceKind::CreditNote));
        self::assertSame([[['name' => 'VAT', 'percent' => '20', 'amount' => '2']], [['name' => 'VAT', 'percent' => '20', 'amount' => '1']], [['name' => 'VAT', 'percent' => '20', 'amount' => '1']]], array_map(static fn(Invoice $credit): array => $credit->taxes(), $credits), 'each credit note says the VAT it gives back');
    }

    public function testAPaymentOfAnotherAmountFlagsTheOrderAndIsTheMostThatGoesBack(): void
    {
        $placed = $this->place(2, 'bank_transfer');
        $this->shop->orders->markPaid($placed, 'BANK-1', amount: '20');

        $order = $this->shop->orders->order($placed);
        self::assertSame(['processing', '20'], [$order->status, $order->refundable]);
        self::assertStringContainsString('The payment was 20 EUR, but the order totals 24 EUR', (string) $order->attention);
        try {
            $this->shop->refunds->refund($placed, new MakeRefund('24'), 7);
            self::fail('more than was paid');
        } catch (OrderRuleViolation $e) {
            self::assertSame('orillacart_invalid_refund_amount', $e->errorCode);
        }
        $this->shop->refunds->refund($placed, new MakeRefund('20'), 7);
        self::assertSame('refunded', $this->shop->orders->order($placed)->status);
    }

    public function testAProviderGivesBackNoMoreThanItsPaymentTook(): void
    {
        $order = $this->paid(2);
        // A second, smaller payment through PayPal: refunds through it stop at its amount.
        $payment = new Payment($order, 'paypal', Money::of('5'), 'EUR', $this->shop->clock->now());
        $payment->refer('PAYPAL-1');
        $payment->complete('CAPTURE-PAYPAL-1', $this->shop->clock->now());
        $this->shop->paymentRecords->save($payment);

        try {
            $this->shop->refunds->refund($order, new MakeRefund('10'), 7);
            self::fail('more than the payment');
        } catch (PaymentRuleViolation $e) {
            self::assertSame('orillacart_refund_over_payment', $e->errorCode);
        }
        $this->shop->refunds->refund($order, new MakeRefund('5'), 7);
        self::assertSame('19', $this->shop->refunds->forOrder($order)->refundable);
    }

    public function testAnOrderAProviderIsAskedToChargeKeepsItsItems(): void
    {
        $placed = $this->place(1, 'paypal');
        $this->shop->orders->hold($placed, 'Waiting.');

        $this->expectException(PaymentRuleViolation::class);
        $this->expectExceptionMessage('has a payment open at paypal for its total');
        $this->shop->editing->edit($placed, new EditOrderItems([new EditedItem(20, 2)]), 7);
    }

    private function paid(int $quantity): int
    {
        $placed = $this->place($quantity, 'bank_transfer');
        $this->shop->orders->markPaidByShop($placed, '', 7);

        return $placed;
    }

    private function place(int $quantity, string $payment): int
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20, quantity: $quantity))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: $payment))->number;
    }
}
