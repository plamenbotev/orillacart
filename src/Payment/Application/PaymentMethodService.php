<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application;

use OrillaCart\Payment\Application\Dto\GatewaySetting;
use OrillaCart\Payment\Application\Dto\PaymentMethodView;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Payment\Application\Gateway\PaymentGateway;
use OrillaCart\Payment\Domain\PaymentMethod;
use OrillaCart\Payment\Domain\PaymentMethodNotFound;
use OrillaCart\Payment\Domain\PaymentMethodRepository;
use OrillaCart\Payment\Domain\PaymentRuleViolation;
use OrillaCart\Settings\Application\SettingsService;

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
     * @throws PaymentRuleViolation  for unknown settings, or a method turned on without its required settings
     */
    public function update(string $gateway, SavePaymentMethod $command): PaymentMethodView
    {
        $found = $this->gateways->get($gateway);
        $method = $this->method($found);
        $settings = $this->settings($found, $method->settings(), $command->settings);
        if ($command->active) {
            foreach ($found->settings() as $field) {
                if ($field->required && ($settings[$field->key] ?? '') === '') {
                    throw new PaymentRuleViolation(\sprintf('%s needs "%s" before it can be turned on.', $command->name, $field->label), 'orillacart_payment_method_incomplete');
                }
            }
        }
        $method->change($command->name, $command->active, $command->position, $command->countries, $command->instructions, $command->receiptNote, $settings);
        $this->methods->save($method);

        return $this->view($found, $method);
    }

    private function method(PaymentGateway $gateway): PaymentMethod
    {
        return $this->methods->findByGateway($gateway->id())
            ?? new PaymentMethod($gateway->id(), $gateway->defaultName(), false, $this->gateways->defaultPosition($gateway->id()));
    }

    /**
     * @param array<string, string|bool> $saved
     * @param array<string, string|bool> $submitted
     *
     * @return array<string, string|bool>
     */
    private function settings(PaymentGateway $gateway, array $saved, array $submitted): array
    {
        $fields = [];
        foreach ($gateway->settings() as $field) {
            $fields[$field->key] = $field;
        }
        foreach (array_keys($submitted) as $key) {
            if (!isset($fields[$key])) {
                throw new PaymentRuleViolation(\sprintf('"%s" is not a setting of %s.', $key, $gateway->defaultName()), 'orillacart_unknown_payment_setting');
            }
        }

        $settings = [];
        foreach ($fields as $key => $field) {
            $value = $submitted[$key] ?? null;
            if ($field->type === GatewaySetting::BOOLEAN) {
                if ($value !== null && !\is_bool($value)) {
                    throw new PaymentRuleViolation(\sprintf('"%s" is on or off.', $field->label), 'orillacart_invalid_payment_setting');
                }
                $settings[$key] = $value ?? false;
                continue;
            }
            if ($value !== null && !\is_string($value)) {
                throw new PaymentRuleViolation(\sprintf('"%s" is text.', $field->label), 'orillacart_invalid_payment_setting');
            }
            $value = trim($value ?? '');
            // An empty secret keeps the saved one: the screen never receives secrets to send back.
            $settings[$key] = $field->type === GatewaySetting::SECRET && $value === '' ? ($saved[$key] ?? '') : $value;
        }

        return $settings;
    }

    private function view(PaymentGateway $gateway, PaymentMethod $method): PaymentMethodView
    {
        $settings = [];
        $secretsSet = [];
        foreach ($gateway->settings() as $field) {
            $value = $method->settings()[$field->key] ?? ($field->type === GatewaySetting::BOOLEAN ? false : '');
            if ($field->type === GatewaySetting::SECRET) {
                if ($value !== '') {
                    $secretsSet[] = $field->key;
                }
                $value = '';
            }
            $settings[$field->key] = $value;
        }

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
            $gateway->isReady($method->settings(), $this->shop->current()->currency),
            $gateway->usesNotifications() ? $this->urls->notificationUrl($gateway->id()) : null,
        );
    }
}
