<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

/**
 * A customer coming back from the provider, to the session's return or cancel address.
 */
final readonly class PaymentReturn
{
    /**
     * @param array<string, string> $query the query string the provider added
     * @param bool                  $cancelled whether they came back to the cancel address
     */
    public function __construct(
        public array $query,
        public bool $cancelled,
    ) {}
}
