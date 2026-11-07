<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Checkout\Fee;
use OrillaCart\Extension\Checkout\FeeQuery;
use OrillaCart\Extension\Checkout\OrderFee;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;

/** `order_fee "id" { fn fee(query) }`: answers fee.of(), or null for no fee. */
final class ScriptedOrderFee extends ScriptedProvider implements OrderFee
{
    public function fee(FeeQuery $query): ?Fee
    {
        try {
            $answer = $this->call('fee', [Values::feeQuery($query)]);
        } catch (HandlerFailed) {
            return null;
        }
        if ($answer === null) {
            return null;
        }
        $fee = $this->expect($answer, Fee::class, 'fee');
        \assert($fee instanceof Fee);

        return $fee;
    }
}
