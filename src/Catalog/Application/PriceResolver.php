<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Domain\PriceRuleRepository;
use OrillaCart\Catalog\Domain\ShopperPrices;
use Psr\Clock\ClockInterface;

/**
 * The shop's price rules for a shopper: what their groups pay now. One per request or task, not per item.
 */
final readonly class PriceResolver
{
    public function __construct(
        private PriceRuleRepository $rules,
        private ShopperGroups $groups,
        private ClockInterface $clock,
    ) {}

    public function forShopper(?int $customerId): ShopperPrices
    {
        return new ShopperPrices($this->rules->active(), $this->groups->groupIdsOf($customerId), $this->clock->now());
    }
}
