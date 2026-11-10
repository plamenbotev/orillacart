<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application;

use OrillaCart\GiftCards\Domain\GiftCardSettingsRepository;

/**
 * Which products are sold as gift cards, for what an order of them is: money to spend, not goods. They count
 * towards no threshold (free shipping, coupons' minimum, the minimum order), and, as the shop chooses, are paid
 * for with money only.
 */
final readonly class GiftCardProducts
{
    public function __construct(
        private GiftCardSettingsRepository $settings,
    ) {}

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        return $this->settings->load()->productIds;
    }

    /**
     * Whether gift cards and store credit are refused for an order holding gift cards: a card bought with
     * credit tied to a customer would turn it into a card anyone may spend.
     *
     * @param list<int> $productIds the order's products
     */
    public function refusesCards(array $productIds): bool
    {
        $settings = $this->settings->load();

        return $settings->paidWithMoneyOnly && array_any($productIds, $settings->sellsAsGiftCard(...));
    }
}
