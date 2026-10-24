<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Customers;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Customers\Application\PersonalData;
use OrillaCart\GiftCards\Application\Dto\IssueGiftCard;
use OrillaCart\Ordering\Application\Dto\AddOrderNote;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class PersonalDataTest extends TestCase
{
    private TestShop $shop;
    private AddressData $address;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->address = new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000');
    }

    public function testAPersonsOrdersAndAddressesAreExported(): void
    {
        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        $ordered = $this->place(new CartOwner(null, $ada));
        $asGuest = $this->place(new CartOwner(null), 'ADA@example.com');
        $this->place(new CartOwner(null), 'grace@example.com');

        $export = $this->shop->personalData->export('ada@example.com', 1);

        self::assertTrue($export['done']);
        $saved = $this->shop->customers->customer($ada)->addresses[0]->id;
        self::assertSame(['orillacart-account-address-' . $saved, 'orillacart-order-' . $ordered->number, 'orillacart-order-' . $asGuest->number], array_column($export['items'], 'id'), 'the account, then the orders by account and by email, oldest first');
        self::assertSame('Ada Lovelace, 1 Vitosha Blvd, Sofia, 1000, BG, +359 2 000 0000', $export['items'][0]['data'][0]['value']);
        $order = array_column($export['items'][1]['data'], 'value', 'name');
        self::assertSame(['#' . $ordered->number, 'ada@example.com', '1 × E-book'], [$order['Order'], $order['Email'], $order['Items']]);
    }

    public function testTheWishlistIsExportedByProductName(): void
    {
        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        $this->shop->accounts->saveWishlist($ada, [20, 999]);

        $items = array_column($this->shop->personalData->export('ada@example.com', 1)['items'], null, 'id');

        self::assertSame([['name' => 'Wishlist', 'value' => 'E-book, #999']], $items['orillacart-account-wishlist']['data'] ?? null, 'a product gone since by its number');
    }

    public function testErasingAnonymisesOrdersAndForgetsTheRest(): void
    {
        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        $ordered = $this->place(new CartOwner(null, $ada));
        $asGuest = $this->place(new CartOwner(null), 'ada@example.com');
        $grace = $this->place(new CartOwner(null), 'grace@example.com');
        $this->shop->carts->add(new CartOwner(null, $ada), new AddToCart(20));

        $erased = $this->shop->personalData->erase('ada@example.com', 1);

        self::assertSame(['removed' => true, 'retained' => true, 'messages' => ['2 orders were kept for the books, with the customer\'s details erased.'], 'done' => true], $erased);
        foreach ([$ordered, $asGuest] as $placed) {
            $order = $this->shop->orders->order($placed->number);
            self::assertTrue($order->anonymised);
            self::assertSame(['anonymised@invalid', 'Anonymised', '', '', null, 'BG', '8'], [$order->email, $order->billing->firstName, $order->billing->street, $order->billing->phone, $order->customerId, $order->billing->country, $order->total], 'what the books need stays');
            self::assertSame('The customer\'s details were erased, as a privacy request asked.', $order->notes[\count($order->notes) - 1]->text);
        }
        self::assertSame('grace@example.com', $this->shop->orders->order($grace->number)->email, 'someone else is untouched');
        $customer = $this->shop->customers->customer($ada);
        self::assertNull($customer->billing, 'the account forgets its addresses');
        self::assertSame([], array_filter($this->shop->cartRecords->carts, static fn($cart): bool => $cart->customerId() === $ada), 'and its cart');

        self::assertSame([], $this->shop->personalData->export('ada@example.com', 1)['items']);
        self::assertSame(['removed' => false, 'retained' => false, 'messages' => [], 'done' => true], $this->shop->personalData->erase('ada@example.com', 1), 'nothing is left to erase');
    }

    public function testErasingTakesThePersonOutOfTheOrdersNotesAndForgetsTheirWishlist(): void
    {
        $ada = $this->shop->accounts->add('ada@example.com', 'Ada');
        $ordered = $this->place(new CartOwner(null, $ada));
        $this->shop->orders->addNote($ordered->number, new AddOrderNote('Called Ada Lovelace on +359 2 000 0000; the parcel goes to 1 Vitosha Blvd, a copy to ada@example.com.'), 7);
        $this->shop->accounts->saveWishlist($ada, [20]);

        $this->shop->personalData->erase('ada@example.com', 1);

        $notes = implode(' ', array_map(static fn($note): string => $note->text, $this->shop->orders->order($ordered->number)->notes));
        self::assertStringContainsString('Called [erased] on [erased]; the parcel goes to [erased], a copy to [erased].', $notes);
        self::assertSame([], $this->shop->accounts->wishlist($ada));
    }

    public function testAGiftCardMadeOutToThePersonIsInTheirExport(): void
    {
        $this->shop->giftCards->issue(new IssueGiftCard('25', recipientName: 'Ada', recipientEmail: 'ada@example.com', message: 'Happy birthday'), 1);

        $items = array_values(array_filter($this->shop->personalData->export('ada@example.com', 1)['items'], static fn(array $item): bool => $item['group'] === 'orillacart-gift-cards'));

        self::assertCount(1, $items);
        self::assertSame(['Ada', 'Happy birthday', '25 EUR'], [array_column($items[0]['data'], 'value', 'name')['For'], array_column($items[0]['data'], 'value', 'name')['Message'], array_column($items[0]['data'], 'value', 'name')['Left']]);
    }

    public function testManyOrdersAreErasedAPageAtATime(): void
    {
        for ($i = 0; $i <= PersonalData::PER_PAGE; ++$i) {
            $this->place(new CartOwner(null));
        }

        self::assertFalse($this->shop->personalData->erase('ada@example.com', 1)['done']);
        self::assertTrue($this->shop->personalData->erase('ada@example.com', 2)['done']);
        self::assertSame([], $this->shop->personalData->export('ada@example.com', 1)['items']);
    }

    private function place(CartOwner $owner, string $email = 'ada@example.com'): PlacedOrderView
    {
        $result = $this->shop->carts->add($owner, new AddToCart(20));

        return $this->shop->checkout->placeOrder(new CartOwner($result->newToken ?? $owner->token, $owner->customerId), new PlaceOrder($email, $this->address, payment: 'bank_transfer', rememberAddresses: true));
    }
}
