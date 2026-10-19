<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Domain\PriceRuleRepository;
use OrillaCart\Catalog\Domain\ShopperPrices;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Extension\ExtensionFailures;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The prices one shopper pays: the shop's price rules for their customer groups, then the add-ons' adjusters.
 */
final readonly class PriceResolver
{
    public function __construct(
        private PriceRuleRepository $rules,
        private ShopperGroups $groups,
        private ClockInterface $clock,
        private InstalledExtensions $extensions,
        private SettingsService $settings,
        private LoggerInterface $logger,
        private ExtensionFailures $failures,
    ) {}

    /** @param int|null $customerId null for a guest, and for the search index */
    public function forShopper(?int $customerId): ShopperPricing
    {
        return new ShopperPricing(
            new ShopperPrices($this->rules->active(), $this->groups->groupIdsOf($customerId), $this->clock->now()),
            $this->extensions->priceAdjusters(),
            $customerId,
            $this->settings->current()->currency,
            $this->logger,
            $this->settings->current()->priceDecimals,
            $this->failures,
        );
    }
}
