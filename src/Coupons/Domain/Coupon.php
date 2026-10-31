<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\Text;

/**
 * A code customers enter for a discount: what it takes off, on which items, when and for whom it holds, and
 * how often it may be used. Codes are compared without case. An automatic discount is one with no code to enter:
 * it applies wherever it holds, and customers see its description.
 *
 * Buy X, get Y: each time the order has the items to buy and the items to give, the items given come a
 * percentage off; the dearest matching items count as bought and the cheapest as given. A free gift is a product
 * the order gets for nothing once the rest of it holds: checkout adds it, or makes the one in the cart free.
 *
 * Shown to shoppers (advertised), the promotion blocks tell of it: what it gives, and what the cart still needs
 * for it (progress()), by the same rules checkout applies it by.
 */
#[ORM\Entity]
#[ORM\Table(name: 'coupons')]
#[ORM\UniqueConstraint(name: 'coupon_code', columns: ['code'])]
class Coupon
{
    public const int MAX_CODE_LENGTH = 40;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_CODE_LENGTH)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column(length: 20, enumType: CouponKind::class)]
    private CouponKind $kind;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $amount;

    /** The most a percentage takes off the order. */
    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, nullable: true)]
    private ?string $cap;

    #[ORM\Column]
    private bool $active;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, nullable: true)]
    private ?string $minSubtotal;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS, nullable: true)]
    private ?string $maxSubtotal;

    /** How many matching items the order needs. */
    #[ORM\Column]
    private int $minQuantity;

    /** @var list<string> ISO 3166-1 alpha-2; none: everywhere */
    #[ORM\Column(type: 'json')]
    private array $countries;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $productIds;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $categoryIds;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $brandIds;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $excludedProductIds;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $excludedCategoryIds;

    #[ORM\Column]
    private bool $excludeSaleItems;

    /** It comes off prices whose price rule keeps coupons out. */
    #[ORM\Column(options: ['default' => false])]
    private bool $overridesPriceRules = false;

    /** Free shipping on every way to ship; otherwise on the cheapest one offered. */
    #[ORM\Column(options: ['default' => false])]
    private bool $freeShippingEveryRate = false;

    #[ORM\Column]
    private bool $combines;

    #[ORM\Column(nullable: true)]
    private ?int $usageLimit;

    #[ORM\Column(nullable: true)]
    private ?int $perCustomerLimit;

    #[ORM\Column]
    private bool $firstOrderOnly;

    /** Orders that use it, not cancelled or failed. */
    #[ORM\Column]
    private int $uses = 0;

    /** Buy-get: how many to buy, and how many it gives off, each time; a free gift: how many of it. */
    #[ORM\Column(options: ['default' => 0])]
    private int $buyQuantity = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $getQuantity = 0;

    /** @var list<int> buy-get: the items it gives off; none of these or the categories: the matching items */
    #[ORM\Column(type: 'json')]
    private array $getProductIds = [];

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $getCategoryIds = [];

    #[ORM\Column(nullable: true)]
    private ?int $giftProductId = null;

    /** It applies by itself; its code is the shop's own and is never entered. */
    #[ORM\Column(options: ['default' => false])]
    private bool $automatic;

    /** The promotion blocks tell shoppers of it, a code with the code to enter. */
    #[ORM\Column(options: ['default' => false])]
    private bool $advertised;

    /**
     * @param bool|null $advertised null for the default: an automatic discount is shown to shoppers, a code is not
     */
    public function __construct(CouponTerms $terms, bool $automatic = false, ?bool $advertised = null)
    {
        $this->automatic = $automatic;
        $this->advertised = $advertised ?? $automatic;
        $this->change($terms);
        if ($automatic && $this->description === '') {
            throw new CouponRuleViolation('An automatic discount has a description: customers see it instead of a code.', 'orillacart_invalid_coupon_description');
        }
    }

    public function isAutomatic(): bool
    {
        return $this->automatic;
    }

    public function isAdvertised(): bool
    {
        return $this->advertised;
    }

    /** Whether the promotion blocks tell shoppers of it; null for the default (shown when automatic). */
    public function advertise(?bool $advertised): void
    {
        $this->advertised = $advertised ?? $this->automatic;
    }

    public static function normalize(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /**
     * @throws CouponRuleViolation for terms that do not make a coupon
     */
    public function change(CouponTerms $terms): void
    {
        $code = self::normalize($terms->code);
        if ($code === '' || mb_strlen($code) > self::MAX_CODE_LENGTH || preg_match('/^[\p{L}\p{N}_-]+$/u', $code) !== 1) {
            throw new CouponRuleViolation('A code has up to %d letters, digits, dashes and underscores.', 'orillacart_invalid_coupon_code', values: [self::MAX_CODE_LENGTH]);
        }
        if ($terms->kind === CouponKind::Percent && (!$terms->amount->isPercentOff() || $terms->amount->isZero())) {
            throw new CouponRuleViolation('A percentage is more than 0 and at most 100.', 'orillacart_invalid_coupon_amount');
        }
        if ($terms->kind === CouponKind::BuyGet && (!$terms->amount->isPercentOff() || $terms->amount->isZero())) {
            throw new CouponRuleViolation('The items given are discounted by more than 0 and at most 100%.', 'orillacart_invalid_coupon_amount');
        }
        if ($terms->kind === CouponKind::BuyGet && ($terms->buyQuantity < 1 || $terms->getQuantity < 1)) {
            throw new CouponRuleViolation('Say how many items to buy and how many it gives, at least 1 each.', 'orillacart_invalid_coupon_quantities');
        }
        if ($terms->kind === CouponKind::FreeGift && ($terms->giftProductId === null || $terms->giftProductId < 1 || $terms->getQuantity < 1)) {
            throw new CouponRuleViolation('A free gift names the product given, and at least 1 of it.', 'orillacart_invalid_coupon_gift');
        }
        if (!\in_array($terms->kind, [CouponKind::FreeShipping, CouponKind::FreeGift], true) && ($terms->amount->isZero() || $terms->amount->isNegative())) {
            throw new CouponRuleViolation('A coupon takes off more than nothing.', 'orillacart_invalid_coupon_amount');
        }
        if ($terms->startsAt !== null && $terms->endsAt !== null && $terms->endsAt <= $terms->startsAt) {
            throw new CouponRuleViolation('A coupon ends after it starts.', 'orillacart_invalid_coupon_dates');
        }
        if ($terms->minSubtotal !== null && $terms->maxSubtotal !== null && $terms->maxSubtotal->compare($terms->minSubtotal) < 0) {
            throw new CouponRuleViolation('The largest order is no smaller than the smallest.', 'orillacart_invalid_coupon_subtotal');
        }
        if ($terms->usageLimit !== null && $terms->usageLimit < 1 || $terms->perCustomerLimit !== null && $terms->perCustomerLimit < 1 || $terms->minQuantity < 0) {
            throw new CouponRuleViolation('Limits are at least 1.', 'orillacart_invalid_coupon_limit');
        }
        $this->code = $code;
        $this->description = mb_substr(trim($terms->description), 0, 255);
        $this->kind = $terms->kind;
        $this->amount = (\in_array($terms->kind, [CouponKind::FreeShipping, CouponKind::FreeGift], true) ? Money::zero() : $terms->amount)->toFixed();
        $this->cap = \in_array($terms->kind, [CouponKind::Percent, CouponKind::BuyGet], true) ? $terms->cap?->toFixed() : null;
        $this->active = $terms->active;
        $this->startsAt = $terms->startsAt;
        $this->endsAt = $terms->endsAt;
        $this->minSubtotal = $terms->minSubtotal?->toFixed();
        $this->maxSubtotal = $terms->maxSubtotal?->toFixed();
        $this->minQuantity = $terms->minQuantity;
        $this->countries = array_values(array_unique(array_map(strtoupper(...), $terms->countries)));
        $this->productIds = self::ids($terms->productIds);
        $this->categoryIds = self::ids($terms->categoryIds);
        $this->brandIds = self::ids($terms->brandIds);
        $this->excludedProductIds = self::ids($terms->excludedProductIds);
        $this->excludedCategoryIds = self::ids($terms->excludedCategoryIds);
        $this->excludeSaleItems = $terms->excludeSaleItems;
        $this->overridesPriceRules = $terms->overridesPriceRules;
        $this->freeShippingEveryRate = $terms->freeShippingEveryRate;
        $this->combines = $terms->combines;
        $this->usageLimit = $terms->usageLimit;
        $this->perCustomerLimit = $terms->perCustomerLimit;
        $this->firstOrderOnly = $terms->firstOrderOnly;
        $buyGet = $terms->kind === CouponKind::BuyGet;
        $this->buyQuantity = $buyGet ? $terms->buyQuantity : 0;
        $this->getQuantity = $buyGet || $terms->kind === CouponKind::FreeGift ? $terms->getQuantity : 0;
        $this->getProductIds = $buyGet ? self::ids($terms->getProductIds) : [];
        $this->getCategoryIds = $buyGet ? self::ids($terms->getCategoryIds) : [];
        $this->giftProductId = $terms->kind === CouponKind::FreeGift ? $terms->giftProductId : null;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The coupon has no id until it is saved.');
    }

    public function code(): string
    {
        return $this->code;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function kind(): CouponKind
    {
        return $this->kind;
    }

    public function combines(): bool
    {
        return $this->combines;
    }

    public function uses(): int
    {
        return $this->uses;
    }

    public function terms(): CouponTerms
    {
        return new CouponTerms(
            $this->code,
            $this->kind,
            Money::of($this->amount),
            $this->description,
            $this->cap === null ? null : Money::of($this->cap),
            $this->active,
            $this->startsAt,
            $this->endsAt,
            $this->minSubtotal === null ? null : Money::of($this->minSubtotal),
            $this->maxSubtotal === null ? null : Money::of($this->maxSubtotal),
            $this->minQuantity,
            $this->countries,
            $this->productIds,
            $this->categoryIds,
            $this->brandIds,
            $this->excludedProductIds,
            $this->excludedCategoryIds,
            $this->excludeSaleItems,
            $this->combines,
            $this->usageLimit,
            $this->perCustomerLimit,
            $this->firstOrderOnly,
            $this->buyQuantity,
            $this->getQuantity,
            $this->getProductIds,
            $this->getCategoryIds,
            $this->giftProductId,
            $this->overridesPriceRules,
            $this->freeShippingEveryRate,
        );
    }

    /** Whether the shop can offer the code now, as a reminder does: on, begun, not ended or used up, and typed. */
    public function offerable(\DateTimeImmutable $now): bool
    {
        return !$this->automatic
            && $this->active
            && ($this->startsAt === null || $now >= $this->startsAt)
            && ($this->endsAt === null || $now < $this->endsAt)
            && ($this->usageLimit === null || $this->uses < $this->usageLimit);
    }

    /** Whether it holds only for some customers, by what they ordered or used before: it waits to know who. */
    public function dependsOnWho(): bool
    {
        return $this->firstOrderOnly || $this->perCustomerLimit !== null;
    }

    /**
     * Why the coupon does not hold for the basket, in words for the customer; null when it does.
     *
     * @param int $customerUses how often this customer used it on orders that stand
     */
    public function refusal(Basket $basket, \DateTimeImmutable $now, int $customerUses): ?Text
    {
        return $this->unavailable($basket, $now, $customerUses) ?? $this->unmet($basket);
    }

    /**
     * Whether the shopper could still have it, whatever the cart holds: on, begun, not ended or used up, theirs to
     * use (a first order, uses left) and for their country, when known. The promotion blocks show no other.
     *
     * @param int $customerUses how often this customer used it on orders that stand
     */
    public function obtainable(Basket $basket, \DateTimeImmutable $now, int $customerUses): bool
    {
        return $this->unavailable($basket, $now, $customerUses) === null;
    }

    /**
     * How far the basket is from what the coupon needs of it, measured as refusal() measures: the order's size
     * before any discount (a free gift's own lines left out), the matching items, and the items buy-get gives.
     * Null when no cart could reach it by adding items: the order is past its largest size.
     */
    public function progress(Basket $basket): ?CouponProgress
    {
        [$subtotal, , $units] = $this->measured($basket);
        if ($this->maxSubtotal !== null && $subtotal->compare($basket->inCurrency(Money::of($this->maxSubtotal))) > 0) {
            return null;
        }
        $buyGet = $this->kind === CouponKind::BuyGet;

        return new CouponProgress(
            $subtotal,
            $this->minSubtotal === null ? null : $basket->inCurrency(Money::of($this->minSubtotal)),
            $units,
            // Buy-get giving off the items it applies to needs those given too.
            max(1, $this->minQuantity, $this->buyQuantity + ($buyGet && !$this->givesOthers() ? $this->getQuantity : 0)),
            $buyGet && $this->givesOthers() && $this->giftable($basket) === [],
        );
    }

    /**
     * Whether the coupon applies to the item, as it would in a cart: one it is for and does not leave out (nor a
     * free gift's own product).
     */
    public function appliesTo(BasketLine $line): bool
    {
        return $this->matching(new Basket([$line], '')) !== [];
    }

    /** Why it cannot hold for this shopper now, whatever the cart; null when it may. */
    private function unavailable(Basket $basket, \DateTimeImmutable $now, int $customerUses): ?Text
    {
        return match (true) {
            !$this->active => new Text('This code is not valid.'),
            $this->startsAt !== null && $now < $this->startsAt => new Text('This code is not valid yet.'),
            $this->endsAt !== null && $now >= $this->endsAt => new Text('This code has expired.'),
            $this->usageLimit !== null && $this->uses >= $this->usageLimit => new Text('This code has been used up.'),
            $this->perCustomerLimit !== null && $customerUses >= $this->perCustomerLimit => new Text('You have already used this code.'),
            $this->firstOrderOnly && !$basket->firstOrder => new Text('This code is for a first order only.'),
            $this->countries !== [] && $basket->country !== null && !\in_array($basket->country, $this->countries, true) => new Text('This code is not valid for your country.'),
            default => null,
        };
    }

    /** What the cart lacks for it: its size, the items it applies to, those buy-get gives; null for nothing. */
    private function unmet(Basket $basket): ?Text
    {
        [$subtotal, $matching, $units] = $this->measured($basket);

        return match (true) {
            $this->minSubtotal !== null && $subtotal->compare($basket->inCurrency(Money::of($this->minSubtotal))) < 0 => Text::of('This code needs an order of at least %1$s %2$s.', $basket->inCurrency(Money::of($this->minSubtotal))->rounded(new Currency($basket->currency)->decimals()), $basket->currency),
            $this->maxSubtotal !== null && $subtotal->compare($basket->inCurrency(Money::of($this->maxSubtotal))) > 0 => Text::of('This code is for orders of up to %1$s %2$s.', $basket->inCurrency(Money::of($this->maxSubtotal))->rounded(new Currency($basket->currency)->decimals()), $basket->currency),
            $matching === [] => new Text('This code does not apply to the items in your cart.'),
            $units < max(1, $this->minQuantity, $this->buyQuantity) => Text::of('This code needs at least %d of the items it applies to.', max($this->minQuantity, $this->buyQuantity)),
            $this->kind === CouponKind::BuyGet && !$this->givesOthers() && $units < $this->buyQuantity + $this->getQuantity => Text::of('This code needs %1$d of the items it applies to: %2$d to buy and %3$d it gives.', $this->buyQuantity + $this->getQuantity, $this->buyQuantity, $this->getQuantity),
            $this->kind === CouponKind::BuyGet && $this->givesOthers() && $this->giftable($basket) === [] => new Text('Add the items this code gives off to your cart.'),
            default => null,
        };
    }

    /**
     * What the rules on the cart's size judge: its items before any discount (a free gift's own lines do not count
     * towards the order they are given with), the matching lines, and how many items those hold.
     *
     * @return array{Money, array<int, BasketLine>, int}
     */
    private function measured(Basket $basket): array
    {
        $subtotal = $basket->subtotal();
        $matching = $this->matching($basket);
        if ($this->kind === CouponKind::FreeGift) {
            foreach ($this->giftLines($basket) as $line) {
                $subtotal = $subtotal->subtract($line->total);
            }
        }

        return [$subtotal, $matching, array_sum(array_map(static fn(BasketLine $line): int => $line->quantity, $matching))];
    }

    /**
     * What it takes off each line, by the line's index in the basket: never more than the line costs. A free
     * shipping coupon takes nothing off the items.
     *
     * @return array<int, Money>
     */
    public function discounts(Basket $basket): array
    {
        $matching = $this->matching($basket);
        // A percentage is the same in any currency; an amount is the shop's.
        $amount = \in_array($this->kind, [CouponKind::OrderAmount, CouponKind::ItemAmount], true) ? $basket->inCurrency(Money::of($this->amount)) : Money::of($this->amount);
        $discounts = [];
        switch ($this->kind) {
            case CouponKind::FreeShipping:
                return [];
            case CouponKind::FreeGift:
                $left = $this->getQuantity;
                foreach ($this->giftLines($basket) as $index => $line) {
                    $given = min($left, $line->quantity);
                    $discounts[$index] = self::atMost($line->total->proportion(Money::of((string) $given), Money::of((string) $line->quantity)), $line->total);
                    $left -= $given;
                }

                return array_filter($discounts, static fn(Money $discount): bool => !$discount->isZero());
            case CouponKind::BuyGet:
                $discounts = $this->buyGet($basket);
                $cap = $this->cap === null ? null : $basket->inCurrency(Money::of($this->cap));

                return $cap === null || $discounts === [] ? $discounts : self::spread(self::atMost(self::sum($discounts), $cap), $discounts);
            case CouponKind::ItemAmount:
                foreach ($matching as $index => $line) {
                    $discounts[$index] = self::atMost($amount->times($line->quantity), $line->total);
                }

                return $discounts;
            case CouponKind::Percent:
                foreach ($matching as $index => $line) {
                    $discounts[$index] = $line->total->percent($this->amount);
                }
                $cap = $this->cap === null ? null : $basket->inCurrency(Money::of($this->cap));

                return $cap === null ? $discounts : self::spread(self::atMost(self::sum($discounts), $cap), $discounts);
            case CouponKind::OrderAmount:
                $totals = array_map(static fn(BasketLine $line): Money => $line->total, $matching);

                return self::spread(self::atMost($amount, self::sum($totals)), $totals);
        }
    }

    public function freeShipping(): bool
    {
        return $this->kind === CouponKind::FreeShipping;
    }

    /** Its free shipping covers every way to ship, not only the cheapest. */
    public function freeShippingEveryRate(): bool
    {
        return $this->freeShipping() && $this->freeShippingEveryRate;
    }

    /**
     * A free gift the order still needs to have added: the product and how many, besides those already in it.
     *
     * @return array{productId: int, quantity: int}|null
     */
    public function missingGift(Basket $basket): ?array
    {
        if ($this->kind !== CouponKind::FreeGift || $this->giftProductId === null) {
            return null;
        }
        $have = array_sum(array_map(static fn(BasketLine $line): int => $line->quantity, $this->giftLines($basket)));

        return $have >= $this->getQuantity ? null : ['productId' => $this->giftProductId, 'quantity' => $this->getQuantity - $have];
    }

    /**
     * What buy-get takes off, by line: each round, the dearest matching items not yet counted are bought and the
     * cheapest items it gives that are left are given, until there are not enough for another round.
     *
     * @return array<int, Money>
     */
    private function buyGet(Basket $basket): array
    {
        $buyable = self::units($this->matching($basket));
        $giftable = self::units($this->givesOthers() ? $this->giftable($basket) : $this->matching($basket));
        usort($buyable, static fn(array $a, array $b): int => [$b['price'], $a['line'], $a['unit']] <=> [$a['price'], $b['line'], $b['unit']]);
        usort($giftable, static fn(array $a, array $b): int => [$a['price'], $a['line'], $a['unit']] <=> [$b['price'], $b['line'], $b['unit']]);
        $used = [];
        $discounts = [];
        while (true) {
            $bought = self::pick($buyable, $this->buyQuantity, $used);
            if ($bought === null) {
                break;
            }
            $given = self::pick($giftable, $this->getQuantity, $used + $bought);
            if ($given === null) {
                break;
            }
            $used += $bought + $given;
            foreach ($given as $unit) {
                $discounts[$unit['line']] = ($discounts[$unit['line']] ?? Money::zero())->add(Money::fromTenThousandths($unit['price'])->percent($this->amount));
            }
        }
        foreach ($discounts as $index => $discount) {
            $discounts[$index] = self::atMost($discount, $basket->lines[$index]->total);
        }
        ksort($discounts);

        return $discounts;
    }

    /**
     * @param list<array{line: int, unit: int, price: int}> $units in the order to take them
     * @param array<string, array{line: int, unit: int, price: int}> $used
     *
     * @return array<string, array{line: int, unit: int, price: int}>|null the first $count not used, by key
     */
    private static function pick(array $units, int $count, array $used): ?array
    {
        $picked = [];
        foreach ($units as $unit) {
            if (\count($picked) === $count) {
                break;
            }
            $key = "{$unit['line']}:{$unit['unit']}";
            if (!isset($used[$key])) {
                $picked[$key] = $unit;
            }
        }

        return \count($picked) === $count ? $picked : null;
    }

    /**
     * Each item of the lines on its own, at its share of what the line costs now.
     *
     * @param array<int, BasketLine> $lines
     *
     * @return list<array{line: int, unit: int, price: int}> prices in ten-thousandths
     */
    private static function units(array $lines): array
    {
        $units = [];
        foreach ($lines as $index => $line) {
            $price = $line->total->proportion(Money::of('1'), Money::of((string) $line->quantity))->tenThousandths;
            for ($unit = 0; $unit < $line->quantity; ++$unit) {
                $units[] = ['line' => $index, 'unit' => $unit, 'price' => $price];
            }
        }

        return $units;
    }

    /** Buy-get names the items it gives, rather than giving off those it applies to. */
    private function givesOthers(): bool
    {
        return $this->getProductIds !== [] || $this->getCategoryIds !== [];
    }

    /**
     * The lines buy-get gives off when it names its own: left out of no coupon (excluded products and categories,
     * sale items when it leaves them, prices a price rule keeps coupons off), and not free already.
     *
     * @return array<int, BasketLine>
     */
    private function giftable(Basket $basket): array
    {
        $giftable = [];
        foreach ($basket->lines as $index => $line) {
            $named = \in_array($line->productId, $this->getProductIds, true) || array_intersect($line->categoryIds, $this->getCategoryIds) !== [];
            if ($named && !$this->excludes($line) && !$line->total->isZero()) {
                $giftable[$index] = $line;
            }
        }

        return $giftable;
    }

    /**
     * The lines of a free gift's product, by their index; the gift is given off the last first.
     *
     * @return array<int, BasketLine>
     */
    private function giftLines(Basket $basket): array
    {
        $lines = array_filter($basket->lines, fn(BasketLine $line): bool => $this->giftProductId !== null && $line->productId === $this->giftProductId && !$line->total->isZero());

        return array_reverse($lines, true);
    }

    /** An order uses it. */
    public function use(): void
    {
        ++$this->uses;
    }

    /** An order that used it was cancelled or failed. */
    public function release(): void
    {
        $this->uses = max(0, $this->uses - 1);
    }

    /** Whether the coupon leaves the line out, whatever it applies to. */
    private function excludes(BasketLine $line): bool
    {
        return \in_array($line->productId, $this->excludedProductIds, true)
            || array_intersect($line->categoryIds, $this->excludedCategoryIds) !== []
            || ($this->excludeSaleItems && $line->onSale)
            || (!$line->coupons && !$this->overridesPriceRules);
    }

    /**
     * The lines the coupon applies to, by their index.
     *
     * @return array<int, BasketLine>
     */
    private function matching(Basket $basket): array
    {
        $matching = [];
        foreach ($basket->lines as $index => $line) {
            $targeted = $this->productIds === [] && $this->categoryIds === [] && $this->brandIds === []
                || \in_array($line->productId, $this->productIds, true)
                || array_intersect($line->categoryIds, $this->categoryIds) !== []
                || array_intersect($line->brandIds, $this->brandIds) !== [];
            $excluded = $this->excludes($line);
            $gift = $this->kind === CouponKind::FreeGift && $line->productId === $this->giftProductId;
            if ($targeted && !$excluded && !$gift && !$line->total->isZero()) {
                $matching[$index] = $line;
            }
        }

        return $matching;
    }

    /**
     * Splits an amount over the lines in proportion to their weights; the largest line takes the rounding, as
     * it has the most room for it.
     *
     * @param array<int, Money> $weights
     *
     * @return array<int, Money>
     */
    private static function spread(Money $amount, array $weights): array
    {
        $whole = self::sum($weights);
        $largest = array_key_first($weights);
        if ($whole->isZero() || $largest === null) {
            return [];
        }
        foreach ($weights as $index => $weight) {
            if ($weight->compare($weights[$largest]) > 0) {
                $largest = $index;
            }
        }
        $shares = [];
        $left = $amount;
        foreach ($weights as $index => $weight) {
            if ($index !== $largest) {
                $shares[$index] = self::atMost($amount->proportion($weight, $whole), $weight);
                $left = $left->subtract($shares[$index]);
            }
        }
        $shares[$largest] = self::atMost($left, $weights[$largest]);
        ksort($shares);

        return $shares;
    }

    /**
     * @param array<int, Money> $amounts
     */
    private static function sum(array $amounts): Money
    {
        return array_reduce($amounts, static fn(Money $sum, Money $amount): Money => $sum->add($amount), Money::zero());
    }

    private static function atMost(Money $amount, Money $limit): Money
    {
        return $amount->compare($limit) > 0 ? $limit : $amount;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    }
}
