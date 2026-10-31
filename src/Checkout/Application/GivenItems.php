<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CartContents;
use OrillaCart\Cart\Domain\GivenItem;
use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\ProductQuotes;
use OrillaCart\Coupons\Application\Discounts;
use OrillaCart\Coupons\Application\Dto\AppliedDiscounts;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tax\Application\Dto\TaxAddress;

/**
 * The items coupons give, kept in the cart as lines of their own (a free gift, the items buy-get gives free): what
 * the shopper's own items earn, priced as checkout prices them, for sale in that quantity besides those ordered,
 * and free once added. Kept in step whenever the cart changes, and at checkout with the address and email given.
 */
final readonly class GivenItems
{
    public function __construct(
        private CartService $carts,
        private OrderPricing $pricing,
        private ProductQuotes $quotes,
        private Currencies $currencies,
        private Discounts $discounts,
    ) {}

    /**
     * @param TaxAddress|null $at    where the order goes, or the estimate's address
     * @param string|null     $email the customer's, once given: for discounts some customers only get
     *
     * @return bool whether the cart changed
     */
    public function keep(int $cartId, ?TaxAddress $at, ?string $email = null): bool
    {
        $contents = $this->carts->contentsById($cartId);
        if ($contents === null) {
            return false;
        }
        try {
            $items = $this->earned($contents, $at, $email);
        } catch (RuleViolation) {
            // An item no longer sold, or not in that quantity: the cart says why, and keeps what it gives.
            return false;
        }

        return $this->carts->keepGiven($cartId, $items);
    }

    /**
     * @return list<GivenItem>
     *
     * @throws RuleViolation
     */
    private function earned(CartContents $contents, ?TaxAddress $at, ?string $email): array
    {
        $own = array_values(array_filter($contents->lines, static fn(array $line): bool => $line['given'] === null));
        // Priced only when a coupon could give something: pricing may ask a tax service.
        if ($own === [] || !$this->discounts->mayGiveItems($contents->coupons)) {
            return [];
        }
        $currency = $this->currencies->current();
        $priced = $this->price($own, $contents, $at, $email, $currency);
        $items = [];
        $lines = [];
        foreach ($priced['discounts']->gifts as $gift) {
            $same = array_values(array_filter($own, static fn(array $line): bool => $line['product_id'] === $gift['productId']));
            // One of a product ordered is as the shopper chose it there.
            $options = $same[0]['option_ids'] ?? [];
            $choices = $same[0]['bundle_choices'] ?? [];
            $quantity = $gift['quantity'] + array_sum(array_map(static fn(array $line): int => $line['quantity'], $same));
            try {
                $quote = $this->quotes->quote(new QuoteRequest($gift['productId'], $options, $quantity, $contents->customerId, $choices));
            } catch (NotFound|RuleViolation) {
                continue;
            }
            if ($quote->forSale && ($quote->available === null || $quote->available >= $quantity)) {
                $items[] = new GivenItem($gift['code'], $gift['description'], $gift['productId'], $options, $choices, $gift['quantity']);
                $lines[] = ['product_id' => $gift['productId'], 'option_ids' => $options, 'bundle_choices' => $choices, 'quantity' => $gift['quantity'], 'given' => $gift['code']];
            }
        }
        if ($items === []) {
            return [];
        }
        // Added, they must come free: a coupon may leave one out after all (as on sale), and the shopper did not
        // choose to pay for it.
        $withGiven = $this->price([...$own, ...$lines], $contents, $at, $email, $currency);
        if (!OrderPricing::unearnedGiven($withGiven, $this->currencies->decimals($currency))) {
            return $items;
        }
        $free = [];
        foreach ($items as $item) {
            $alone = $this->price([...$own, ...array_values(array_filter($lines, static fn(array $line): bool => $line['given'] === $item->code))], $contents, $at, $email, $currency);
            if (!OrderPricing::unearnedGiven($alone, $this->currencies->decimals($currency))) {
                $free[] = $item;
            }
        }

        return $free;
    }

    /**
     * @param list<array{product_id: int, option_ids: list<int>, bundle_choices?: list<int>, quantity: int, given?: ?string, extra?: string, personalisation?: list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}>}> $lines
     *
     * @return array{lines: list<array{given: ?string, total: Money, ...}>, discounts: AppliedDiscounts, ...}
     *
     * @throws RuleViolation
     */
    private function price(array $lines, CartContents $contents, ?TaxAddress $at, ?string $email, string $currency): array
    {
        return $this->pricing->price($lines, $at, $at, null, $at?->postcode, $contents->coupons, $contents->customerId, $email, currency: $currency, itemsOnly: true);
    }
}
