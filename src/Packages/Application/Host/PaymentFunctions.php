<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Host;

use OrillaCart\Extension\Payment\PaymentOutcome;
use OrillaCart\Extension\Payment\PaymentStart;
use OrillaCart\Extension\Payment\RefundResult;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\HostNamespace;

/**
 * `payment`: what a scripted payment gateway answers with, the add-on API's own outcomes. A script passes them
 * back as it got them; it cannot look inside.
 */
final readonly class PaymentFunctions implements HostNamespace
{
    public function functions(): array
    {
        return [
            new HostFunction('payment.redirect', static fn(Context $c, mixed $url, mixed $reference): PaymentStart => PaymentStart::redirect(Values::text($url), Values::text($reference)), 2, 2, 'payment.redirect(url, reference): send the customer to the provider; the reference names the payment from then on'),
            new HostFunction('payment.offline', static fn(Context $c): PaymentStart => PaymentStart::offline(), 0, 0, 'payment.offline(): the order is placed and paid outside the shop (a transfer)'),
            new HostFunction('payment.completed', static fn(Context $c, mixed $reference, mixed $transactionId, mixed $amount = null, mixed $currency = null): PaymentOutcome => PaymentOutcome::completed(Values::text($reference), Values::text($transactionId), Values::amount($amount), Values::optionalText($currency)), 2, 4, 'payment.completed(reference, transaction_id, amount?, currency?): the payment is made'),
            new HostFunction('payment.held', static fn(Context $c, mixed $reference, mixed $transactionId = null): PaymentOutcome => PaymentOutcome::held(Values::text($reference), Values::optionalText($transactionId)), 1, 2, 'payment.held(reference, transaction_id?): the provider holds it for review'),
            new HostFunction('payment.failed', static fn(Context $c, mixed $reference, mixed $transactionId = null): PaymentOutcome => PaymentOutcome::failed(Values::text($reference), Values::optionalText($transactionId)), 1, 2, 'payment.failed(reference, transaction_id?): the payment failed'),
            new HostFunction('payment.cancelled', static fn(Context $c, mixed $reference): PaymentOutcome => PaymentOutcome::cancelled(Values::text($reference)), 1, 1, 'payment.cancelled(reference): the customer gave up'),
            new HostFunction('payment.pending', static fn(Context $c, mixed $reference): PaymentOutcome => PaymentOutcome::pending(Values::text($reference)), 1, 1, 'payment.pending(reference): nothing is known yet; the shop asks again later'),
            new HostFunction('payment.disputed', static fn(Context $c, mixed $reference, mixed $reason, mixed $transactionId = null): PaymentOutcome => PaymentOutcome::disputed(Values::text($reference), Values::text($reason), Values::optionalText($transactionId)), 2, 3, 'payment.disputed(reference, reason, transaction_id?): the customer disputes it with the provider'),
            new HostFunction('payment.refunded', static fn(Context $c, mixed $reference, mixed $refundId, mixed $amount, mixed $currency = null, mixed $transactionId = null, mixed $request = null): PaymentOutcome => PaymentOutcome::refunded(Values::text($reference), Values::text($refundId), Values::amount($amount) ?? '0', Values::optionalText($currency), Values::optionalText($transactionId), Values::optionalText($request)), 3, 6, 'payment.refunded(reference, refund_id, amount, currency?, transaction_id?, request?): the provider refunded this much'),
            new HostFunction('payment.refund_done', static fn(Context $c, mixed $reference): RefundResult => RefundResult::done(Values::text($reference)), 1, 1, 'payment.refund_done(reference): the refund is made'),
            new HostFunction('payment.refund_pending', static fn(Context $c, mixed $reference): RefundResult => RefundResult::pending(Values::text($reference)), 1, 1, 'payment.refund_pending(reference): the provider will refund; the shop checks later'),
        ];
    }
}
