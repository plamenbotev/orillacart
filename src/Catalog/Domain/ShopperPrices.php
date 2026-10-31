<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * The price rules as they hold for one shopper now. Each rule that holds gives a price for the quantity; the
 * lowest wins, and never more than the price itself, so rules never add up by surprise. Rules that say they combine
 * then apply on top, in the order they were made, each only when it lowers the price.
 */
final readonly class ShopperPrices
{
    /**
     * @param list<PriceRule> $rules  the active ones
     * @param list<int>       $groups the shopper's customer groups
     */
    public function __construct(
        private array $rules,
        private array $groups,
        private \DateTimeImmutable $now,
    ) {}

    /** The price of each of this many items. */
    public function price(Money $price, PricedItem $item, int $quantity = 1): Money
    {
        return $this->priced($price, $item, $quantity)[0];
    }

    /**
     * The price of each of this many items, and whether codes and automatic discounts still come off it: not when
     * a rule that set it says they do not.
     *
     * @return array{Money, bool}
     */
    public function priced(Money $price, PricedItem $item, int $quantity = 1): array
    {
        $best = $price;
        $coupons = true;
        $combining = [];
        foreach ($this->rules as $rule) {
            if (!$rule->holdsFor($item, $this->groups, $this->now)) {
                continue;
            }
            if ($rule->combines()) {
                $combining[] = $rule;
                continue;
            }
            $candidate = $rule->priceFor($price, $quantity);
            if ($candidate !== null && $candidate->compare($best) < 0) {
                $best = $candidate;
                $coupons = $rule->goesWithCoupons();
            }
        }
        usort($combining, static fn(PriceRule $a, PriceRule $b): int => $a->id() <=> $b->id());
        foreach ($combining as $rule) {
            $candidate = $rule->priceFor($best, $quantity);
            if ($candidate !== null && $candidate->compare($best) < 0) {
                $best = $candidate;
                $coupons = $coupons && $rule->goesWithCoupons();
            }
        }

        return [$best, $coupons];
    }

    /**
     * The price from each quantity where it changes, for the product page: [[1, "10"], [10, "9"]]. Empty when
     * the quantity does not change it.
     *
     * @param (\Closure(Money, int): Money)|null $then changes each quantity's price further, after the rules
     *
     * @return list<array{min_quantity: int, price: string}>
     */
    public function tiers(Money $price, PricedItem $item, ?\Closure $then = null): array
    {
        $quantities = [1];
        foreach ($this->rules as $rule) {
            if ($rule->holdsFor($item, $this->groups, $this->now)) {
                foreach ($rule->tiers() as $tier) {
                    $quantities[] = $tier->minQuantity;
                }
            }
        }
        $quantities = array_values(array_unique($quantities));
        sort($quantities);
        $tiers = [];
        $last = null;
        foreach ($quantities as $quantity) {
            $at = $this->price($price, $item, $quantity);
            if ($then !== null) {
                $at = $then($at, $quantity);
            }
            if ($last === null || !$at->equals($last)) {
                $tiers[] = ['min_quantity' => $quantity, 'price' => (string) $at];
                $last = $at;
            }
        }

        return \count($tiers) > 1 ? $tiers : [];
    }

    /**
     * The quantities above this one where a rule that holds for the item has a tier, lowest first: where its price
     * may change as more are bought.
     *
     * @return list<int>
     */
    public function tierQuantities(PricedItem $item, int $above): array
    {
        $quantities = [];
        foreach ($this->rules as $rule) {
            if ($rule->holdsFor($item, $this->groups, $this->now)) {
                foreach ($rule->tiers() as $tier) {
                    if ($tier->minQuantity > $above) {
                        $quantities[$tier->minQuantity] = $tier->minQuantity;
                    }
                }
            }
        }
        sort($quantities);

        return $quantities;
    }

    /** When a rule that holds for the item starts or ends next, for the guest price the search index keeps. */
    public function nextChange(PricedItem $item): ?\DateTimeImmutable
    {
        $next = null;
        foreach ($this->rules as $rule) {
            // A moment inside the rule's dates for each of its two ends: whether it holds for the item then.
            foreach ([[$rule->startsAt(), $rule->startsAt()], [$rule->endsAt(), $rule->endsAt()?->modify('-1 second')]] as [$moment, $inside]) {
                if ($moment !== null && $inside !== null && $moment > $this->now && ($next === null || $moment < $next) && $rule->holdsFor($item, [], $inside)) {
                    $next = $moment;
                }
            }
        }

        return $next;
    }
}
