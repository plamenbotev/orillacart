<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Pricing\Adjustment;
use OrillaCart\Extension\Pricing\AdjustmentQuery;
use OrillaCart\Extension\Pricing\OrderAdjuster;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Scripting\Domain\Ops;

/** `order_adjuster "id" { fn adjust(query) }`: answers a list of price.adjustment(). */
final class ScriptedOrderAdjuster extends ScriptedProvider implements OrderAdjuster
{
    public function adjust(AdjustmentQuery $query): array
    {
        try {
            $answer = $this->call('adjust', [Values::adjustmentQuery($query)]);
        } catch (HandlerFailed) {
            return [];
        }
        $adjustments = [];
        foreach (Ops::iterable($answer === null ? [] : $answer) as $item) {
            $adjustment = $this->expect($item, Adjustment::class, 'adjust');
            \assert($adjustment instanceof Adjustment);
            $adjustments[] = $adjustment;
        }

        return $adjustments;
    }
}
