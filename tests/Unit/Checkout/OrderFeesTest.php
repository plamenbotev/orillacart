<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CheckoutFeeView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Extension\Checkout\Fee;
use OrillaCart\Extension\Checkout\FeeQuery;
use OrillaCart\Extension\Checkout\OrderFee;
use OrillaCart\Extension\FailsClosed;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\NewOrderFee;
use OrillaCart\Ordering\Application\OrderSummaries;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Application\Dto\StartPayment;
use OrillaCart\Payment\Domain\PaymentRuleViolation;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class OrderFeesTest extends TestCase
{
    private TestShop $shop;
    private int $courier;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $vat = $this->shop->taxGroups->create(new SaveTaxGroup('Standard'))->id;
        $this->shop->taxGroups->addRate($vat, new SaveTaxRate('BG', null, '20', 'VAT'));
        $this->shop->taxSettings->save(new TaxSettings(TaxBasis::ShippingAddress, $vat));
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('33.33', weight: '2.5'));
        $this->shop->content->add(30, 'Sticker');
        $this->shop->products->save(30, new SaveProduct('0', 'virtual'));
        $this->courier = $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Bulgaria', '5', regions: ['BG'])]))->rates[0]->id;
        $this->shop->paymentMethods->update('cash_on_delivery', new SavePaymentMethod('Cash on delivery', true, fee: '2', feePercent: '1', feeLabel: 'Cash on delivery fee'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true, 2));
    }

    public function testTheWayToPayAddsItsFeeTaxedAsAnItem(): void
    {
        $owner = $this->cart(10);

        // 2 and 1 % of the lamp and shipping (38.33): 2.38, and 20 % VAT on it.
        $summary = $this->shop->checkout->summary($owner, new CheckoutDetails('BG', payment: 'cash_on_delivery'));
        self::assertSame(['cash_on_delivery', ['cash_on_delivery' => '2.38']], [$summary->paymentMethod, $summary->paymentFees]);
        self::assertEquals([new CheckoutFeeView('Cash on delivery fee', '2.38')], $summary->fees);
        self::assertSame(['0.48', '48.86'], [$summary->feesTax, $summary->total]);
        self::assertSame([['VAT', '8.15']], array_map(static fn($tax): array => [$tax->name, $tax->total], $summary->taxes));

        $byTransfer = $this->shop->checkout->summary($owner, new CheckoutDetails('BG', payment: 'bank_transfer'));
        self::assertSame([[], '46'], [$byTransfer->fees, $byTransfer->total]);
        self::assertSame('cash_on_delivery', $this->shop->checkout->summary($owner, new CheckoutDetails('BG'))->paymentMethod, 'the first offered, as the form ticks it');

        $placed = $this->shop->checkout->placeOrder($owner, $this->order('cash_on_delivery', '48.86'));
        $order = $this->shop->orders->order($placed->number);
        self::assertEquals([new NewOrderFee('Cash on delivery fee', '2.38')], $order->fees);
        self::assertSame(['2.38', '0.48', '48.86'], [$order->feesTotal, $order->feesTax, $order->total]);
        self::assertSame(['Cash on delivery fee' => '2.38'], OrderSummaries::summary($order)->fees);
    }

    public function testAnOrderWithNothingToPayPaysNoFeeForPaying(): void
    {
        $summary = $this->shop->checkout->summary($this->cart(30), new CheckoutDetails('BG', payment: 'cash_on_delivery'));

        self::assertSame([[], '0', false], [$summary->fees, $summary->total, $summary->needsPayment]);
    }

    public function testAnAddOnsFeeIsChargedAndOneThatFailsIsLeftOutAndNoted(): void
    {
        $this->shop->extensions->fees[] = new class implements OrderFee {
            public function id(): string
            {
                return 'heavy';
            }

            public function fee(FeeQuery $query): ?Fee
            {
                return $query->items[0]->quantity > 1 ? new Fee('Heavy order', '3', false) : null;
            }
        };
        $this->shop->extensions->fees[] = new class implements OrderFee {
            public function id(): string
            {
                return 'broken';
            }

            public function fee(FeeQuery $query): ?Fee
            {
                throw new \RuntimeException('down');
            }
        };

        $summary = $this->shop->checkout->summary($this->cart(10, 2), new CheckoutDetails('BG', payment: 'bank_transfer'));
        self::assertEquals([new CheckoutFeeView('Heavy order', '3')], $summary->fees);
        self::assertSame(['0', '88.99'], [$summary->feesTax, $summary->total], 'untaxed: 66.66 + 13.33 VAT + 5 + 1 VAT + 3');

        $placed = $this->shop->checkout->placeOrder($this->cart(10, 2), $this->order('bank_transfer', '88.99'));
        $notes = array_map(static fn($note): string => $note->text, $this->shop->orders->order($placed->number)->notes);
        self::assertContains('The order fee broken failed; the order was priced without it.', $notes);
        self::assertContains(['error', 'broken'], array_map(static fn(array $record): array => [$record['level'], $record['context']['fee'] ?? null], $this->shop->logger->records));
    }

    public function testAFeeTheShopCannotDoWithoutRefusesTheOrderWhenItFails(): void
    {
        $this->shop->extensions->fees[] = new class implements OrderFee, FailsClosed {
            public function id(): string
            {
                return 'insurance';
            }

            public function fee(FeeQuery $query): ?Fee
            {
                throw new \RuntimeException('down');
            }
        };

        $this->expectException(CheckoutRuleViolation::class);
        $this->expectExceptionMessage('Some prices cannot be worked out right now.');
        $this->shop->checkout->placeOrder($this->cart(10), $this->order('bank_transfer'));
    }

    public function testPayPalIsToldTheFeesAsHandling(): void
    {
        $this->shop->paymentMethods->update('paypal', new SavePaymentMethod('PayPal', true, 3, settings: ['client_id' => 'id', 'client_secret' => 'secret', 'sandbox' => true], fee: '1'));

        $placed = $this->shop->checkout->placeOrder($this->cart(10), $this->order('paypal'));
        $start = StartPayment::forOrder($this->shop->orders->order($placed->number), 'paypal');

        self::assertSame(['33.33', '7.87', '5', '1', '47.2'], [$start->breakdown?->itemTotal, $start->breakdown?->tax, $start->breakdown?->shipping, $start->breakdown?->handling, $start->amount]);
        self::assertSame('1.00', $this->shop->payPalApi->created[0]->breakdown['handling'] ?? null);
    }

    public function testARefundOfTheWholeOrderGivesBackTheFeesTaxToo(): void
    {
        $placed = $this->shop->checkout->placeOrder($this->cart(10), $this->order('cash_on_delivery'));
        $this->shop->orders->markPaid($placed->number, null);

        $this->shop->refunds->refund($placed->number, new MakeRefund('48.86', outside: true), null);

        $order = $this->shop->orders->order($placed->number);
        self::assertSame(['refunded', '48.86', '0'], [$order->status, $order->refundedTotal, $order->refundable]);
    }

    public function testAFeeIsZeroOrMoreAndAPercentageAtMostAHundred(): void
    {
        $this->expectException(PaymentRuleViolation::class);
        $this->shop->paymentMethods->update('cheque', new SavePaymentMethod('Cheque', fee: '1', feePercent: '101'));
    }

    private function cart(int $product, int $quantity = 1): CartOwner
    {
        return new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart($product, quantity: $quantity))->newToken);
    }

    private function order(string $payment, ?string $expectedTotal = null): PlaceOrder
    {
        return new PlaceOrder(
            'ada@example.com',
            new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'),
            shippingOption: 'rate:' . $this->courier,
            payment: $payment,
            expectedTotal: $expectedTotal,
        );
    }
}
