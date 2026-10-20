<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Customers;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Customers\Application\Dto\AddCustomerNote;
use OrillaCart\Customers\Application\Dto\CustomerQuery;
use OrillaCart\Customers\Domain\CustomerRuleViolation;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class CustomerInsightsTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('10', 'virtual'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
    }

    public function testALifetimeValueIsWhatTheCustomerPaidLessWhatWentBack(): void
    {
        $ada = $this->shop->accounts->add('ada@example.com');
        $first = $this->place($ada, 2);
        $this->shop->clock->modify('+10 days');
        $second = $this->place($ada, 1);
        $this->shop->clock->modify('+10 days');
        $this->place($ada, 5);
        $this->shop->orders->markPaid($first->number, null);
        $this->shop->orders->markPaid($second->number, null);
        $this->shop->refunds->refund($first->number, new MakeRefund('5', outside: true), null);

        $value = $this->shop->customers->customer($ada)->lifetimeValue;

        self::assertNotNull($value);
        self::assertSame(['EUR', '25', 2, '12.5'], [$value->currency, $value->spent, $value->paidOrders, $value->average], '20 + 10 paid, 5 back; the unpaid one counts for nothing');
        self::assertSame([$this->shop->orders->order($first->number)->placedAt, $this->shop->orders->order($first->number + 2)->placedAt], [$value->firstOrderAt, $value->lastOrderAt]);
        self::assertSame('25', $this->shop->customers->customers(new CustomerQuery())->customers[0]->lifetimeValue?->spent, 'the list shows it too');
    }

    public function testACustomerWithoutOrdersIsWorthNothingYet(): void
    {
        $value = $this->shop->customers->customer($this->shop->accounts->add('grace@example.com'))->lifetimeValue;

        self::assertSame(['0', 0, '0', null], [$value?->spent, $value?->paidOrders, $value?->average, $value?->lastOrderAt]);
    }

    public function testTheShopNotesAboutACustomerAndTheNotesGoWithThePerson(): void
    {
        $ada = $this->shop->accounts->add('ada@example.com');
        $this->shop->customerNotes->add($ada, new AddCustomerNote('Prefers calls before delivery.'), 7);
        $this->shop->clock->modify('+1 minute');
        $second = $this->shop->customerNotes->add($ada, new AddCustomerNote('  Wholesale prices agreed.  '), 99);

        $notes = $this->shop->customerNotes->of($ada);
        self::assertSame([['Wholesale prices agreed.', null], ['Prefers calls before delivery.', 'Mara']], array_map(static fn($note): array => [$note->text, $note->author], $notes), 'newest first; an author whose account is gone has no name');

        $this->shop->customerNotes->remove($ada, $second->id);
        self::assertCount(1, $this->shop->customerNotes->of($ada));
        try {
            $this->shop->customerNotes->remove($this->shop->accounts->add('grace@example.com'), $notes[1]->id);
            self::fail('a note about someone else');
        } catch (CustomerRuleViolation) {
        }

        self::assertTrue($this->shop->customers->erase('ada@example.com'));
        self::assertSame([], $this->shop->customerNotes->of($ada));
    }

    private function place(int $customer, int $quantity): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null, $customer), new AddToCart(20, quantity: $quantity))->newToken, $customer);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));
    }
}
