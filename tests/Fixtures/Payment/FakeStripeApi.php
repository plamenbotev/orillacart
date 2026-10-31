<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Payment;

use OrillaCart\Payment\Application\Stripe\StripeApi;
use OrillaCart\Payment\Application\Stripe\StripeRefused;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;

/**
 * Plays Stripe: opens Checkout Sessions, which the test pays or lets lapse, and takes refunds.
 */
final class FakeStripeApi implements StripeApi
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>, key: ?string}> every request, in order */
    public array $requests = [];
    /** @var array<string, array<string, mixed>> the sessions opened, by id */
    public array $sessions = [];
    /** @var array<string, array<string, mixed>> the refunds taken, by id */
    public array $refunds = [];
    public bool $reachable = true;
    /** @var array{request: string, code: string, message: string}|null Stripe refusing the next requests of a kind ("POST /v1/refunds"): its code and message */
    public ?array $refuses = null;
    /** Stripe's status of the refunds it takes: succeeded, pending, failed. */
    public string $refundStatus = 'succeeded';

    public function request(string $secretKey, string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params, 'key' => $idempotencyKey];
        if (!$this->reachable) {
            throw new PaymentProviderUnavailable('Stripe', 'Stripe could not be reached.');
        }
        if ($this->refuses !== null && $this->refuses['request'] === "{$method} {$path}") {
            throw new StripeRefused($this->refuses['message'], $this->refuses['code']);
        }
        if ($method === 'POST' && $path === '/v1/checkout/sessions') {
            $id = 'cs_test_' . (\count($this->sessions) + 1);
            /** @var list<array{quantity: int, price_data: array{currency: string, unit_amount: int}}> $lines */
            $lines = $params['line_items'];
            $total = array_sum(array_map(static fn(array $line): int => $line['price_data']['unit_amount'] * $line['quantity'], $lines));
            $this->sessions[$id] = ['id' => $id, 'object' => 'checkout.session', 'status' => 'open', 'payment_status' => 'unpaid', 'amount_total' => $total, 'currency' => $lines[0]['price_data']['currency'], 'payment_intent' => null, 'url' => "https://checkout.stripe.com/c/pay/{$id}"];

            return $this->sessions[$id];
        }
        if (preg_match('#^/v1/checkout/sessions/(cs_[^/]+)(/expire)?$#', $path, $match) === 1) {
            $session = $this->sessions[$match[1]] ?? throw new StripeRefused("No such checkout.session: '{$match[1]}'", 'resource_missing');
            if (($match[2] ?? '') === '/expire') {
                if ($session['status'] !== 'open') {
                    throw new StripeRefused('Only Checkout Sessions with a status in [open] can be expired.', '');
                }
                $this->sessions[$match[1]]['status'] = 'expired';

                return $this->sessions[$match[1]];
            }

            return $session;
        }
        if ($method === 'POST' && $path === '/v1/refunds') {
            $id = 're_' . (\count($this->refunds) + 1);
            $this->refunds[$id] = ['id' => $id, 'object' => 'refund', 'amount' => $params['amount'], 'currency' => 'eur', 'payment_intent' => $params['payment_intent'], 'status' => $this->refundStatus, 'metadata' => $params['metadata']];

            return $this->refunds[$id];
        }
        if (preg_match('#^/v1/refunds/(re_\w+)$#', $path, $match) === 1) {
            $this->refunds[$match[1]]['status'] = $this->refundStatus;

            return $this->refunds[$match[1]];
        }
        if ($path === '/v1/balance') {
            return ['object' => 'balance'];
        }

        throw new \LogicException("FakeStripeApi does not answer {$method} {$path}.");
    }

    /**
     * The customer finishes the session: paid by card, or by a bank debit still on its way (unpaid, processing).
     *
     * @return array<string, mixed> the session as Stripe now tells of it
     */
    public function complete(string $id, string $paymentStatus = 'paid', string $intentStatus = 'succeeded'): array
    {
        $this->sessions[$id]['status'] = 'complete';
        $this->sessions[$id]['payment_status'] = $paymentStatus;
        $this->sessions[$id]['payment_intent'] = ['id' => 'pi_' . substr($id, 8), 'status' => $intentStatus];

        return $this->sessions[$id];
    }
}
