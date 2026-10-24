<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Extension\Payment\HandlesNotifications;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Settings;
use OrillaCart\Payment\Application\Dto\PaymentMethodView;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Domain\PaymentMethod;
use OrillaCart\Payment\Domain\PaymentMethodNotFound;
use OrillaCart\Payment\Domain\PaymentMethodRepository;
use OrillaCart\Payment\Domain\PaymentRuleViolation;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Extension\SettingValues;

/**
 * Use cases for payment methods, as the Payments admin screen runs them. Every gateway has a method; one the
 * shop never set up is off, under the gateway's own name.
 */
final readonly class PaymentMethodService
{
    public function __construct(
        private PaymentMethodRepository $methods,
        private Gateways $gateways,
        private SettingsService $shop,
        private PaymentUrls $urls,
    ) {}

    /**
     * @return list<PaymentMethodView> by position
     */
    public function methods(): array
    {
        $views = array_map(fn(PaymentGateway $gateway): PaymentMethodView => $this->view($gateway, $this->method($gateway)), $this->gateways->all());
        usort($views, static fn(PaymentMethodView $a, PaymentMethodView $b): int => [$a->position, $a->gateway] <=> [$b->position, $b->gateway]);

        return $views;
    }

    /**
     * @throws PaymentMethodNotFound for an unknown gateway
     */
    public function show(string $gateway): PaymentMethodView
    {
        $found = $this->gateways->get($gateway);

        return $this->view($found, $this->method($found));
    }

    /**
     * @throws PaymentMethodNotFound for an unknown gateway
     * @throws RuleViolation        for unknown settings or values of the wrong kind
     * @throws PaymentRuleViolation for a method turned on without its required settings
     */
    public function update(string $gateway, SavePaymentMethod $command): PaymentMethodView
    {
        $found = $this->gateways->get($gateway);
        $method = $this->method($found);
        $settings = SettingValues::accept($found->settings(), $method->settings(), $command->settings, $found->defaultName());
        $missing = $command->active ? SettingValues::missing($found->settings(), $settings) : null;
        if ($missing !== null) {
            throw new PaymentRuleViolation('%1$s needs "%2$s" before it can be turned on.', 'orillacart_payment_method_incomplete', values: [$command->name, $missing->label]);
        }
        $method->change($command->name, $command->active, $command->position, $command->countries, $command->instructions, $command->receiptNote, $settings);
        $method->chargeFee(Money::of($command->fee), $command->feePercent, $command->feeLabel);
        $this->methods->save($method);

        return $this->view($found, $method);
    }

    private function method(PaymentGateway $gateway): PaymentMethod
    {
        return $this->methods->findByGateway($gateway->id())
            ?? new PaymentMethod($gateway->id(), $gateway->defaultName(), false, $this->gateways->defaultPosition($gateway->id()));
    }

    private function view(PaymentGateway $gateway, PaymentMethod $method): PaymentMethodView
    {
        [$settings, $secretsSet] = SettingValues::shown($gateway->settings(), $method->settings());

        return new PaymentMethodView(
            $gateway->id(),
            $method->name(),
            $method->isActive(),
            $method->position(),
            $method->countries(),
            $method->instructions(),
            $method->receiptNote(),
            $settings,
            $secretsSet,
            $gateway->settings(),
            $gateway->notReady(new Settings($method->settings()), $this->shop->current()->currency, $this->shop->current()->priceDecimals),
            $gateway instanceof HandlesNotifications ? $this->urls->notificationUrl($gateway->id()) : null,
            (string) $method->fee(),
            $method->feePercent(),
            $method->feeLabel() === $method->name() ? '' : $method->feeLabel(),
        );
    }
}
