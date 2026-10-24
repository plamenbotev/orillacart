<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Domain;

/**
 * How the shop sells gift cards: the products bought as one (each paid item makes a card worth what it was paid),
 * and how long a card lasts, for those sold and those the shop gives.
 */
final readonly class GiftCardSettings
{
    public const int MAX_MONTHS = 120;

    /**
     * @param list<int> $productIds products sold as gift cards; they should be virtual and without tax, as a gift
     *                              card is money to spend, taxed when it is spent
     * @param int|null  $validMonths months a card lasts from when it is made; null for ever
     */
    public function __construct(
        public array $productIds = [],
        public ?int $validMonths = null,
    ) {
        if ($validMonths !== null && ($validMonths < 1 || $validMonths > self::MAX_MONTHS)) {
            throw new GiftCardRuleViolation('A gift card lasts 1 to %d months, or for ever.', 'orillacart_invalid_gift_card_months', values: [self::MAX_MONTHS]);
        }
    }

    public function sellsAsGiftCard(int $productId): bool
    {
        return \in_array($productId, $this->productIds, true);
    }

    public function expiry(\DateTimeImmutable $from): ?\DateTimeImmutable
    {
        return $this->validMonths === null ? null : $from->modify("+{$this->validMonths} months");
    }
}
