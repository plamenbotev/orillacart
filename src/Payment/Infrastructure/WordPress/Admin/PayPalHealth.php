<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\WordPress\Admin;

use OrillaCart\Extension\Settings;
use OrillaCart\Payment\Application\PayPal\PayPalGateway;
use OrillaCart\Payment\Domain\NotifiedEvents;
use OrillaCart\Payment\Domain\PaymentMethodRepository;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;

/**
 * Whether PayPal, when the shop offers it, is set up to take live payments and hear of them: without its webhook
 * the shop never learns of a customer who closed the window before coming back, of reversals or of disputes.
 */
final readonly class PayPalHealth implements HealthCheck
{
    public function __construct(
        private PaymentMethodRepository $methods,
        private PayPalGateway $gateway,
        private SettingsService $settings,
        private NotifiedEvents $events,
    ) {}

    public static function id(): string
    {
        return 'paypal';
    }

    public static function label(): string
    {
        return __('PayPal', 'orillacart');
    }

    public function check(): HealthStatus
    {
        $method = $this->methods->findByGateway(PayPalGateway::ID);
        if ($method === null || !$method->isActive()) {
            return HealthStatus::good(__('PayPal is not offered', 'orillacart'));
        }
        $settings = new Settings($method->settings());
        $shop = $this->settings->current();
        $screen = admin_url('admin.php?page=orillacart-payments');
        $problem = $this->gateway->notReady($settings, $shop->currency, $shop->priceDecimals);
        if ($problem !== null) {
            return HealthStatus::critical(__('PayPal is switched on but checkout does not offer it', 'orillacart'), $problem, $screen, __('Open Payments', 'orillacart'));
        }
        if ($settings->flag('sandbox')) {
            return HealthStatus::recommended(__('PayPal takes test payments', 'orillacart'), __('It uses the sandbox: customers cannot pay for real. Turn off Sandbox and enter the live credentials and webhook to take real payments.', 'orillacart'), $screen, __('Open Payments', 'orillacart'));
        }
        if ($settings->text('webhook_id') === '') {
            return HealthStatus::recommended(__('PayPal has no webhook', 'orillacart'), __('The shop does not hear of payments whose customer never came back, of reversals or of disputes. Enter the webhook ID.', 'orillacart'), $screen, __('Open Payments', 'orillacart'));
        }

        return HealthStatus::good(__('PayPal takes live payments', 'orillacart'));
    }

    public function info(): array
    {
        $method = $this->methods->findByGateway(PayPalGateway::ID);
        $settings = new Settings($method?->settings() ?? []);
        $last = $this->events->lastReceivedAt(PayPalGateway::ID);

        return [
            'offered' => ['label' => __('PayPal offered', 'orillacart'), 'value' => $method?->isActive() === true ? __('yes', 'orillacart') : __('no', 'orillacart')],
            'mode' => ['label' => __('PayPal mode', 'orillacart'), 'value' => $settings->flag('sandbox') ? 'sandbox' : 'live'],
            'webhook' => ['label' => __('PayPal webhook ID', 'orillacart'), 'value' => $settings->text('webhook_id') === '' ? __('none', 'orillacart') : __('set', 'orillacart')],
            'last_event' => ['label' => __('PayPal’s last notification', 'orillacart'), 'value' => $last?->format(\DATE_ATOM) ?? __('none in the last month', 'orillacart')],
        ];
    }
}
