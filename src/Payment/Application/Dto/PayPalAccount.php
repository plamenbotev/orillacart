<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

/**
 * The shop's PayPal REST app: its credentials, and whether they are sandbox ones.
 */
final readonly class PayPalAccount
{
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public bool $sandbox,
        public string $webhookId = '',
    ) {}
}
