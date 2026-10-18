<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * A code customers enter for a discount: what it takes off, on which items, when and for whom it holds, and
 * how often it may be used. Codes are compared without case.
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

    public function __construct(CouponTerms $terms)
    {
        $this->change($terms);
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
            throw new CouponRuleViolation(\sprintf('A code has up to %d letters, digits, dashes and underscores.', self::MAX_CODE_LENGTH), 'orillacart_invalid_coupon_code');
        }
        if ($terms->kind === CouponKind::Percent && ($terms->amount->compare(Money::of('100')) > 0 || $terms->amount->isZero())) {
            throw new CouponRuleViolation('A percentage is more than 0 and at most 100.', 'orillacart_invalid_coupon_amount');
        }
        if ($terms->kind !== CouponKind::FreeShipping && ($terms->amount->isZero() || $terms->amount->isNegative())) {
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
        $this->amount = ($terms->kind === CouponKind::FreeShipping ? Money::zero() : $terms->amount)->toFixed();
        $this->cap = $terms->kind === CouponKind::Percent ? $terms->cap?->toFixed() : null;
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
        $this->combines = $terms->combines;
        $this->usageLimit = $terms->usageLimit;
        $this->perCustomerLimit = $terms->perCustomerLimit;
        $this->firstOrderOnly = $terms->firstOrderOnly;
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
        );
    }

    /**
     * Why the coupon does not hold for the basket, in words for the customer; null when it does.
     *
     * @param int $customerUses how often this customer used it on orders that stand
     */
    public function refusal(Basket $basket, \DateTimeImmutable $now, int $customerUses): ?string
    {
        $subtotal = $basket->subtotal();
        $matching = $this->matching($basket);

        return match (true) {
            !$this->active => 'This code is not valid.',
            $this->startsAt !== null && $now < $this->startsAt => 'This code is not valid yet.',
            $this->endsAt !== null && $now >= $this->endsAt => 'This code has expired.',
            $this->usageLimit !== null && $this->uses >= $this->usageLimit => 'This code has been used up.',
            $this->perCustomerLimit !== null && $customerUses >= $this->perCustomerLimit => 'You have already used this code.',
            $this->firstOrderOnly && !$basket->firstOrder => 'This code is for a first order only.',
            $this->countries !== [] && $basket->country !== null && !\in_array($basket->country, $this->countries, true) => 'This code is not valid for your country.',
            $this->minSubtotal !== null && $subtotal->compare(Money::of($this->minSubtotal)) < 0 => \sprintf('This code needs an order of at least %s %s.', Money::of($this->minSubtotal), $basket->currency),
            $this->maxSubtotal !== null && $subtotal->compare(Money::of($this->maxSubtotal)) > 0 => \sprintf('This code is for orders of up to %s %s.', Money::of($this->maxSubtotal), $basket->currency),
            $matching === [] => 'This code does not apply to the items in your cart.',
            array_sum(array_map(static fn(BasketLine $line): int => $line->quantity, $matching)) < max(1, $this->minQuantity) => \sprintf('This code needs at least %d of the items it applies to.', $this->minQuantity),
            default => null,
        };
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
        $amount = Money::of($this->amount);
        $discounts = [];
        switch ($this->kind) {
            case CouponKind::FreeShipping:
                return [];
            case CouponKind::ItemAmount:
                foreach ($matching as $index => $line) {
                    $discounts[$index] = self::atMost($amount->times($line->quantity), $line->total);
                }

                return $discounts;
            case CouponKind::Percent:
                foreach ($matching as $index => $line) {
                    $discounts[$index] = $line->total->percent($this->amount);
                }
                $cap = $this->cap === null ? null : Money::of($this->cap);

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
            $excluded = \in_array($line->productId, $this->excludedProductIds, true)
                || array_intersect($line->categoryIds, $this->excludedCategoryIds) !== []
                || ($this->excludeSaleItems && $line->onSale);
            if ($targeted && !$excluded && !$line->total->isZero()) {
                $matching[$index] = $line;
            }
        }

        return $matching;
    }

    /**
     * Splits an amount over the lines in proportion to their weights; the last line takes the rounding.
     *
     * @param array<int, Money> $weights
     *
     * @return array<int, Money>
     */
    private static function spread(Money $amount, array $weights): array
    {
        $whole = self::sum($weights);
        if ($whole->isZero()) {
            return [];
        }
        $shares = [];
        $left = $amount;
        $last = array_key_last($weights);
        foreach ($weights as $index => $weight) {
            $share = $index === $last ? $left : $amount->proportion($weight, $whole);
            $shares[$index] = self::atMost($share, $weight);
            $left = $left->subtract($shares[$index]);
        }

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
