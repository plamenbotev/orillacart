<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Payment\Application\Dto\PaymentOptionView;
use OrillaCart\Payment\Domain\PaymentMethod;
use OrillaCart\Payment\Domain\PaymentMethodRepository;

/**
 * Which payment methods checkout offers a customer: turned on, set up for the shop's currency, and serving
 * the billing country.
 */
final readonly class PaymentOptions
{
    public function __construct(
        private PaymentMethodRepository $methods,
        private Gateways $gateways,
    ) {}

    /**
     * @param string|null $country the billing country; null while the customer has not given it
     *
     * @return list<PaymentOptionView> by position
     */
    public function available(?string $country, string $currency): array
    {
        $options = [];
        foreach ($this->methods->all() as $method) {
            if ($this->offers($method, $country, $currency)) {
                $options[] = new PaymentOptionView($method->gateway(), $method->name(), $method->instructions());
            }
        }

        return $options;
    }

    public function isAvailable(string $gateway, ?string $country, string $currency): bool
    {
        $method = $this->methods->findByGateway($gateway);

        return $method !== null && $this->offers($method, $country, $currency);
    }

    /** What the customer reads after ordering with the method, such as bank details; empty for none. */
    public function receiptNote(string $gateway): string
    {
        return $this->methods->findByGateway($gateway)?->receiptNote() ?? '';
    }

    public function name(string $gateway): string
    {
        return $this->methods->findByGateway($gateway)?->name() ?? $this->gateways->get($gateway)->defaultName();
    }

    private function offers(PaymentMethod $method, ?string $country, string $currency): bool
    {
        if (!$method->isActive() || !$method->servesCountry($country)) {
            return false;
        }
        foreach ($this->gateways->all() as $gateway) {
            if ($gateway->id() === $method->gateway()) {
                return $gateway->isReady($method->settings(), $currency);
            }
        }

        return false;
    }
}
