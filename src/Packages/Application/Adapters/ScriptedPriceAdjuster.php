<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Pricing\PriceAdjuster;
use OrillaCart\Extension\Pricing\PriceQuery;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;

/** `price_adjuster "id" { fn adjust(query) }`: answers the price to charge, or null to leave it. */
final class ScriptedPriceAdjuster extends ScriptedProvider implements PriceAdjuster
{
    public function adjust(PriceQuery $query): ?string
    {
        try {
            return Values::amount($this->call('adjust', [Values::priceQuery($query)]));
        } catch (HandlerFailed) {
            return null;
        }
    }
}
