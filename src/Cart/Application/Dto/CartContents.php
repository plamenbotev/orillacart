<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application\Dto;

/**
 * What a cart holds, without prices: what checkout reads to price and place an order.
 */
final readonly class CartContents
{
    /**
     * @param list<array{line: int, product_id: int, option_ids: list<int>, bundle_choices: list<int>, quantity: int, extra: string, personalisation: list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}>, given: ?string, add_on: array{type: string, label: string}|null}> $lines
     *                                                                                                                                       extra: what the answers add to each item; given: the code of the discount that gives it, for an item given; add_on: the add-on's type that keeps it
     * @param list<string> $coupons  the codes entered, checked or not
     * @param list<string> $refusals why add-ons' cart rules refuse the cart, for the customer; it cannot be ordered
     * @param list<string> $giftCards the gift card codes entered, checked or not
     */
    public function __construct(
        public int $cartId,
        public array $lines,
        public array $coupons = [],
        public array $refusals = [],
        public array $giftCards = [],
        /** The customer pays with their store credit, as far as it goes. */
        public bool $useStoreCredit = false,
        /** The cart as read (Cart::fingerprint()): an order is placed from this cart, and from no other since. */
        public string $fingerprint = '',
        public ?int $customerId = null,
        /** @var list<string> the discounts whose items the shopper removed, by code */
        public array $declined = [],
    ) {}
}
