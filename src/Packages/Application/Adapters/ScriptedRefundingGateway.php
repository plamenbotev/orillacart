<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Payment\RefundRefused;
use OrillaCart\Extension\Payment\RefundRequest;
use OrillaCart\Extension\Payment\RefundResult;
use OrillaCart\Extension\Payment\RefundsPayments;
use OrillaCart\Extension\Settings;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;

/** A scripted gateway with a `refund(request, settings)` function: the shop offers refunds through it. */
final class ScriptedRefundingGateway extends ScriptedPaymentGateway implements RefundsPayments
{
    public function refund(RefundRequest $request, Settings $settings): RefundResult
    {
        try {
            $result = $this->expect($this->call('refund', [Values::refund($request), self::values($settings)]), RefundResult::class, 'refund');
        } catch (HandlerFailed $e) {
            throw new RefundRefused($e->getMessage(), 0, $e);
        }
        \assert($result instanceof RefundResult);

        return $result;
    }
}
