<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Settings;
use OrillaCart\Payment\Application\Dto\PaymentFeeView;
use OrillaCart\Payment\Application\Dto\PaymentOptionView;
use OrillaCart\Payment\Application\Gateway\OfflineGateway;
use OrillaCart\Payment\Domain\PaymentMethod;
use OrillaCart\Payment\Domain\PaymentMethodRepository;
use OrillaCart\Settings\Application\SettingsService;

/**
 * Which payment methods checkout offers a customer: turned on, set up for the shop's currency, and serving
 * the billing country.
 */
final readonly class PaymentOptions
{
    public function __construct(
        private PaymentMethodRepository $methods,
        private Gateways $gateways,
        private SettingsService $shop,
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

    /** The fee for paying with the method; null when it has none. */
    public function fee(string $gateway): ?PaymentFeeView
    {
        $method = $this->methods->findByGateway($gateway);
        if ($method === null || ($method->fee()->isZero() && $method->feePercent() === '0')) {
            return null;
        }

        return new PaymentFeeView($method->feeLabel(), (string) $method->fee(), $method->feePercent());
    }

    public function name(string $gateway): string
    {
        return $this->methods->findByGateway($gateway)?->name() ?? $this->gateways->get($gateway)->defaultName();
    }

    /**
     * The methods whose orders wait for the customer to pay outside the shop, as by bank transfer: those a
     * reminder helps.
     *
     * @return list<string> their gateways
     */
    public function paidOffline(): array
    {
        return array_values(array_map(
            static fn(OfflineGateway $gateway): string => $gateway->id(),
            array_filter($this->gateways->all(), static fn(PaymentGateway $gateway): bool => $gateway instanceof OfflineGateway && $gateway->paidFirst()),
        ));
    }

    /** Whether the way to pay is paid when the order is handed over, as cash on delivery: delivering it is paying. */
    public function collectsOnDelivery(?string $gateway): bool
    {
        foreach ($this->gateways->all() as $known) {
            if ($known->id() === $gateway) {
                return $known instanceof OfflineGateway && !$known->paidFirst();
            }
        }

        return false;
    }

    private function offers(PaymentMethod $method, ?string $country, string $currency): bool
    {
        if (!$method->isActive() || !$method->servesCountry($country)) {
            return false;
        }
        foreach ($this->gateways->all() as $gateway) {
            if ($gateway->id() === $method->gateway()) {
                return $gateway->notReady(new Settings($method->settings()), $currency, $this->shop->current()->priceDecimals) === null;
            }
        }

        return false;
    }
}
