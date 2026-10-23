<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * A change to a line's price, as the order keeps it: a coupon or an automatic discount (for the whole order, or
 * this line), or a change the shop or an add-on made. Lowering the price is a negative amount.
 */
final readonly class PriceAdjustment
{
    public const string COUPON = 'coupon';
    public const string AUTOMATIC = 'automatic';
    public const string MANUAL = 'manual';
    public const string ADD_ON = 'add_on';
    /** An add-on's order adjuster made it at pricing; it is worked out again whenever the order is priced. */
    public const string ADJUSTER = 'adjuster';
    public const array KINDS = [self::COUPON, self::AUTOMATIC, self::MANUAL, self::ADD_ON, self::ADJUSTER];
    /** The changes the order keeps as they were made, until the shop takes them off. */
    public const array KEPT = [self::MANUAL, self::ADD_ON];

    /** It was one amount for the whole order, shared out over its lines. */
    public const string ORDER = 'order';
    /** It was for this line. */
    public const string LINE = 'line';

    public const int MAX_LABEL_LENGTH = 200;

    /**
     * @param string $kind   one of KINDS
     * @param string $source what made it: the coupon's code, the add-on's id, or the shop manager's user id
     * @param string $scope  ORDER or LINE
     * @param bool   $freeShipping a coupon that also made shipping free
     * @param string|null $change the change it is part of: the shares of one change over the lines have the same,
     *                            two changes alike never; null for coupons and automatic discounts
     */
    public function __construct(
        public string $kind,
        public string $source,
        public string $label,
        public Money $amount,
        public string $scope = self::ORDER,
        public bool $freeShipping = false,
        public ?string $reason = null,
        public ?string $change = null,
    ) {
        if ($change !== null && preg_match('/^[0-9a-f]{16}$/', $change) !== 1) {
            throw new \LogicException(\sprintf('"%s" is not a change\'s key.', $change));
        }
        if (!\in_array($kind, self::KINDS, true) || !\in_array($scope, [self::ORDER, self::LINE], true)) {
            throw new \LogicException(\sprintf('"%s" for the %s is not a price adjustment.', $kind, $scope));
        }
    }

    /** A new change's key. */
    public static function newChange(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function isDiscount(): bool
    {
        return $this->kind === self::COUPON || $this->kind === self::AUTOMATIC;
    }
}
