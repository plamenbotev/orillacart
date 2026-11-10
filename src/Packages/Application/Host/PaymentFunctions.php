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
use OrillaCart\Scripting\Domain\ScriptError;

/**
 * `payment`: what a scripted payment gateway answers with, the add-on API's own outcomes. A script passes them
 * back as it got them; it cannot look inside. A redirect goes over https to a host the extension declared.
 */
final readonly class PaymentFunctions implements HostNamespace
{
    public function functions(): array
    {
        return [
            new HostFunction('payment.redirect', static fn(Context $c, mixed $url, mixed $reference): PaymentStart => PaymentStart::redirect(self::provider($c, Values::text($url)), Values::text($reference)), 2, 2, 'payment.redirect(url, reference): send the customer to the provider, over https to a host declared under needs.http; the reference names the payment from then on', writes: false),
            new HostFunction('payment.offline', static fn(Context $c): PaymentStart => PaymentStart::offline(), 0, 0, 'payment.offline(): the order is placed and paid outside the shop (a transfer)', writes: false),
            new HostFunction('payment.completed', static fn(Context $c, mixed $reference, mixed $transactionId, mixed $amount = null, mixed $currency = null): PaymentOutcome => PaymentOutcome::completed(Values::text($reference), Values::text($transactionId), Values::amount($amount), Values::optionalText($currency)), 2, 4, 'payment.completed(reference, transaction_id, amount?, currency?): the payment is made', writes: false),
            new HostFunction('payment.held', static fn(Context $c, mixed $reference, mixed $transactionId = null): PaymentOutcome => PaymentOutcome::held(Values::text($reference), Values::optionalText($transactionId)), 1, 2, 'payment.held(reference, transaction_id?): the provider holds it for review', writes: false),
            new HostFunction('payment.failed', static fn(Context $c, mixed $reference, mixed $transactionId = null): PaymentOutcome => PaymentOutcome::failed(Values::text($reference), Values::optionalText($transactionId)), 1, 2, 'payment.failed(reference, transaction_id?): the payment failed', writes: false),
            new HostFunction('payment.cancelled', static fn(Context $c, mixed $reference): PaymentOutcome => PaymentOutcome::cancelled(Values::text($reference)), 1, 1, 'payment.cancelled(reference): the customer gave up', writes: false),
            new HostFunction('payment.pending', static fn(Context $c, mixed $reference): PaymentOutcome => PaymentOutcome::pending(Values::text($reference)), 1, 1, 'payment.pending(reference): nothing is known yet; the shop asks again later', writes: false),
            new HostFunction('payment.disputed', static fn(Context $c, mixed $reference, mixed $reason, mixed $transactionId = null): PaymentOutcome => PaymentOutcome::disputed(Values::text($reference), Values::text($reason), Values::optionalText($transactionId)), 2, 3, 'payment.disputed(reference, reason, transaction_id?): the customer disputes it with the provider', writes: false),
            new HostFunction('payment.refunded', static fn(Context $c, mixed $reference, mixed $refundId, mixed $amount, mixed $currency = null, mixed $transactionId = null, mixed $request = null): PaymentOutcome => PaymentOutcome::refunded(Values::text($reference), Values::text($refundId), Values::amount($amount) ?? '0', Values::optionalText($currency), Values::optionalText($transactionId), Values::optionalText($request)), 3, 6, 'payment.refunded(reference, refund_id, amount, currency?, transaction_id?, request?): the provider refunded this much', writes: false),
            new HostFunction('payment.refund_done', static fn(Context $c, mixed $reference): RefundResult => RefundResult::done(Values::text($reference)), 1, 1, 'payment.refund_done(reference): the refund is made', writes: false),
            new HostFunction('payment.refund_pending', static fn(Context $c, mixed $reference): RefundResult => RefundResult::pending(Values::text($reference)), 1, 1, 'payment.refund_pending(reference): the provider will refund; the shop checks later', writes: false),
        ];
    }

    /** The provider's page: over https, on a host the extension declared under needs.http. */
    private static function provider(Context $c, string $url): string
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) !== 1 && preg_match('#^https://#i', $url) === 1 && \is_string($host) && \in_array(strtolower($host), $c->grant->hosts, true)) {
            return $url;
        }

        throw new ScriptError(\sprintf('payment.redirect goes over https to a host declared in needs.http, not %s', \is_string($host) && $host !== '' ? $host : $url));
    }
}
