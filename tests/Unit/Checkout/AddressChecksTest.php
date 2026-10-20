<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckAddresses;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Extension\Checkout\AddressCheck;
use OrillaCart\Extension\Checkout\AddressSuggestion;
use OrillaCart\Extension\Checkout\CheckedAddress;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * Add-ons check the addresses customers enter, suggesting corrections the customer takes or leaves.
 */
final class AddressChecksTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', weight: '2', stockMode: 'always_available'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->shop->extensions->addressChecks[] = new class implements AddressCheck {
            public function id(): string
            {
                return 'broken';
            }

            public function check(CheckedAddress $address): ?AddressSuggestion
            {
                throw new \RuntimeException('register down');
            }
        };
        $this->shop->extensions->addressChecks[] = new class implements AddressCheck {
            public function id(): string
            {
                return 'register';
            }

            public function check(CheckedAddress $address): ?AddressSuggestion
            {
                return match ($address->street) {
                    '1 Vitosha' => AddressSuggestion::corrected('1 Vitosha Blvd', postcode: '1000', message: 'Did you mean this?'),
                    'Nowhere 9' => AddressSuggestion::notFound('We could not find this street in Sofia.'),
                    '1 Vitosha Blvd' => AddressSuggestion::corrected('1 Vitosha Blvd'),
                    default => null,
                };
            }
        };
    }

    public function testACheckSuggestsACorrectionOrSaysItFoundNothing(): void
    {
        $said = $this->shop->addressChecks->suggest(new CheckAddresses(self::address('1 Vitosha'), self::address('Nowhere 9')));

        self::assertNotNull($said->billing);
        self::assertSame([true, 'Did you mean this?', '1 Vitosha Blvd', 'Sofia', '1000'], [$said->billing->found, $said->billing->message, $said->billing->street, $said->billing->city, $said->billing->postcode]);
        self::assertNotNull($said->shippingAddress);
        self::assertSame([false, 'We could not find this street in Sofia.'], [$said->shippingAddress->found, $said->shippingAddress->message]);
        self::assertNull($this->shop->addressChecks->suggest(new CheckAddresses(self::address('1 Vitosha Blvd')))->billing, 'a correction that changes nothing');
        self::assertContains(['error', 'broken'], array_map(static fn(array $record): array => [$record['level'], $record['context']['check'] ?? null], $this->shop->logger->records));
    }

    public function testAnOrderPlacedWithAnAddressTheCheckWouldHaveChangedSaysSoToTheShop(): void
    {
        $rate = $this->shop->carriers->create(new SaveCarrier('Post', rates: [new SaveShippingRate('Anywhere', '4')]))->rates[0]->id;
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(10))->newToken);

        $placed = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', self::address('1 Vitosha'), self::address('Nowhere 9'), "rate:{$rate}", 'bank_transfer'));

        $notes = array_column($this->shop->orders->order($placed->number)->notes, 'text');
        self::assertContains('The billing address check suggested 1 Vitosha Blvd, 1000, Sofia; the customer kept their address.', $notes);
        self::assertContains('The shipping address check could not find the address (We could not find this street in Sofia.); the customer kept it.', $notes);
    }

    private static function address(string $street): AddressData
    {
        return new AddressData('Ada', 'Lovelace', $street, 'Sofia', '1000', 'BG', '+359 2 000 0000');
    }
}
