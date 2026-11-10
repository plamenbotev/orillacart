<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Stripe;

use OrillaCart\Payment\Domain\PaymentProviderUnavailable;

/**
 * Stripe's REST API, as the Stripe gateway uses it: form-encoded requests with the secret key, JSON answers.
 */
interface StripeApi
{
    /**
     * @param array<string, mixed> $params         sent as Stripe's form encoding: nested arrays as a[b][0]=c
     * @param string|null          $idempotencyKey Stripe answers a request sent again with this key as it did the first time
     *
     * @return array<mixed> the object Stripe answered with
     *
     * @throws StripeRefused              Stripe said no to the request itself, such as for a refund of more than is left
     * @throws PaymentProviderUnavailable Stripe could not be reached, refused the key, or failed
     */
    public function request(#[\SensitiveParameter] string $secretKey, string $method, string $path, array $params = [], ?string $idempotencyKey = null): array;
}
