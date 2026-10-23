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
    public const array KINDS = [self::COUPON, self::AUTOMATIC, self::MANUAL, self::ADD_ON];

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
     */
    public function __construct(
        public string $kind,
        public string $source,
        public string $label,
        public Money $amount,
        public string $scope = self::ORDER,
        public bool $freeShipping = false,
        public ?string $reason = null,
    ) {
        if (!\in_array($kind, self::KINDS, true) || !\in_array($scope, [self::ORDER, self::LINE], true)) {
            throw new \LogicException(\sprintf('"%s" for the %s is not a price adjustment.', $kind, $scope));
        }
    }

    public function isDiscount(): bool
    {
        return $this->kind === self::COUPON || $this->kind === self::AUTOMATIC;
    }
}
