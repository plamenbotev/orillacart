<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

/**
 * A payment method as the admin screen shows it. Secret settings come back empty; secrets_set names the
 * secrets that are saved.
 */
final readonly class PaymentMethodView
{
    /**
     * @param list<string>               $countries
     * @param array<string, string|bool> $settings
     * @param list<string>               $secretsSet
     * @param list<GatewaySetting>       $fields
     * @param bool                       $ready           whether checkout can offer it with these settings and the shop's currency
     * @param string|null                $notificationUrl where the provider sends notifications; null for gateways without them
     */
    public function __construct(
        public string $gateway,
        public string $name,
        public bool $active,
        public int $position,
        public array $countries,
        public string $instructions,
        public string $receiptNote,
        public array $settings,
        public array $secretsSet,
        public array $fields,
        public bool $ready,
        public ?string $notificationUrl,
    ) {}
}
