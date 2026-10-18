<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * The price rules as they hold for one shopper now. Each rule that holds gives a price for the quantity; the
 * lowest wins, and never more than the price itself, so rules never add up by surprise.
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
        $best = $price;
        foreach ($this->rules as $rule) {
            if ($rule->holdsFor($item, $this->groups, $this->now)) {
                $candidate = $rule->priceFor($price, $quantity);
                if ($candidate !== null && $candidate->compare($best) < 0) {
                    $best = $candidate;
                }
            }
        }

        return $best;
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
