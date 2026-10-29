<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

use OrillaCart\Extension\Setting;

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
     * @param list<Setting>       $fields
     * @param string|null                $notReady        why checkout cannot offer it with these settings and the shop's currency; null when it can
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
        public ?string $notReady,
        public ?string $notificationUrl,
        public string $fee = '0',
        public string $feePercent = '0',
        /** As the shop entered it: empty for the method's name. */
        public string $feeLabel = '',
        /** Whether its settings can be tried at the provider: the "Test" button. */
        public bool $testable = false,
        /** @var array<string, array<string, string>> its words in the site's other languages, by field and locale */
        public array $translations = [],
    ) {}
}
