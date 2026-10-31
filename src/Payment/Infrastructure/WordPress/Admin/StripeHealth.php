<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\WordPress\Admin;

use OrillaCart\Extension\Settings;
use OrillaCart\Payment\Application\Stripe\StripeGateway;
use OrillaCart\Payment\Domain\NotifiedEvents;
use OrillaCart\Payment\Domain\PaymentMethodRepository;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;

/**
 * Whether Stripe, when the shop offers it, takes live payments and hears of them: without its webhook the shop
 * never learns of a customer who closed the window before coming back, of bank debits that cleared days later,
 * of refunds made in Stripe's dashboard or of disputes.
 */
final readonly class StripeHealth implements HealthCheck
{
    public function __construct(
        private PaymentMethodRepository $methods,
        private StripeGateway $gateway,
        private SettingsService $settings,
        private NotifiedEvents $events,
    ) {}

    public static function id(): string
    {
        return 'stripe';
    }

    public static function label(): string
    {
        return __('Stripe', 'orillacart');
    }

    public function check(): HealthStatus
    {
        $method = $this->methods->findByGateway(StripeGateway::ID);
        if ($method === null || !$method->isActive()) {
            return HealthStatus::good(__('Stripe is not offered', 'orillacart'));
        }
        $settings = new Settings($method->settings());
        $shop = $this->settings->current();
        $screen = admin_url('admin.php?page=orillacart-payments');
        $problem = $this->gateway->notReady($settings, $shop->currency, $shop->priceDecimals);
        if ($problem !== null) {
            return HealthStatus::critical(__('Stripe is switched on but checkout does not offer it', 'orillacart'), $problem, $screen, __('Open Payments', 'orillacart'));
        }
        if ($settings->flag('sandbox')) {
            return HealthStatus::recommended(__('Stripe takes test payments', 'orillacart'), __('It is in test mode: customers cannot pay for real. Turn off Test mode and enter the live key and webhook signing secret to take real payments.', 'orillacart'), $screen, __('Open Payments', 'orillacart'));
        }

        return HealthStatus::good(__('Stripe takes live payments', 'orillacart'));
    }

    public function info(): array
    {
        $method = $this->methods->findByGateway(StripeGateway::ID);
        $settings = new Settings($method?->settings() ?? []);
        $last = $this->events->lastReceivedAt(StripeGateway::ID);

        return [
            'offered' => ['label' => __('Stripe offered', 'orillacart'), 'value' => $method?->isActive() === true ? __('yes', 'orillacart') : __('no', 'orillacart')],
            'mode' => ['label' => __('Stripe mode', 'orillacart'), 'value' => $settings->flag('sandbox') ? 'test' : 'live'],
            'webhook' => ['label' => __('Stripe webhook signing secret', 'orillacart'), 'value' => $settings->text('webhook_secret') === '' ? __('none', 'orillacart') : __('set', 'orillacart')],
            'last_event' => ['label' => __('Stripe’s last notification', 'orillacart'), 'value' => $last?->format(\DATE_ATOM) ?? __('none in the last month', 'orillacart')],
        ];
    }
}
