<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\PayPal;

use OrillaCart\Payment\Application\Dto\GatewaySetting;
use OrillaCart\Payment\Application\Dto\GatewayStart;
use OrillaCart\Payment\Application\Dto\PaymentSession;
use OrillaCart\Payment\Application\Dto\PayPalAccount;
use OrillaCart\Payment\Application\Dto\PayPalOrderRequest;
use OrillaCart\Payment\Application\Gateway\PaymentGateway;
use OrillaCart\Payment\Domain\PaymentProviderUnavailable;
use OrillaCart\Shared\Domain\Money;

/**
 * PayPal Checkout through the Orders API v2: the shop creates a PayPal order for the amount, the customer
 * approves it at PayPal and comes back, and the shop captures it. A verified webhook settles payments whose
 * customer never came back.
 */
final readonly class PayPalGateway implements PaymentGateway
{
    public const string ID = 'paypal';

    /** Currencies PayPal accepts, with the decimals it allows in amounts. */
    private const array CURRENCIES = [
        'AUD' => 2, 'BRL' => 2, 'CAD' => 2, 'CNY' => 2, 'CZK' => 2, 'DKK' => 2, 'EUR' => 2, 'HKD' => 2, 'HUF' => 0,
        'ILS' => 2, 'JPY' => 0, 'MYR' => 2, 'MXN' => 2, 'TWD' => 0, 'NZD' => 2, 'NOK' => 2, 'PHP' => 2, 'PLN' => 2,
        'GBP' => 2, 'SGD' => 2, 'SEK' => 2, 'CHF' => 2, 'THB' => 2, 'USD' => 2,
    ];

    public function __construct(private PayPalApi $api) {}

    public function id(): string
    {
        return self::ID;
    }

    public function defaultName(): string
    {
        return 'PayPal';
    }

    public function usesNotifications(): bool
    {
        return true;
    }

    public function settings(): array
    {
        return [
            new GatewaySetting('client_id', 'Client ID', required: true, help: 'From your REST app at developer.paypal.com.'),
            new GatewaySetting('client_secret', 'Secret', GatewaySetting::SECRET, required: true),
            new GatewaySetting('sandbox', 'Sandbox', GatewaySetting::BOOLEAN, help: 'Take test payments with sandbox credentials.'),
            new GatewaySetting('webhook_id', 'Webhook ID', help: 'Settles payments of customers who close the window before coming back. Subscribe the webhook to the shop\'s webhook address.'),
        ];
    }

    public function isReady(array $settings, string $currency): bool
    {
        $account = self::account($settings);

        return $account->clientId !== '' && $account->clientSecret !== '' && isset(self::CURRENCIES[$currency]);
    }

    public function start(PaymentSession $session, array $settings): GatewayStart
    {
        $amount = Money::of($session->amount);
        $decimals = self::CURRENCIES[$session->currency] ?? throw new PaymentProviderUnavailable('PayPal', "PayPal does not take {$session->currency}.");
        if (!$amount->rounded($decimals)->equals($amount)) {
            throw new PaymentProviderUnavailable('PayPal', "The amount {$amount} has more decimals than PayPal takes in {$session->currency}.");
        }

        $order = $this->api->createOrder(self::account($settings), new PayPalOrderRequest(
            "orillacart-payment-{$session->paymentId}",
            "orillacart-{$session->orderNumber}-{$session->paymentId}",
            "Order #{$session->orderNumber}",
            $amount->format($decimals),
            $session->currency,
            $session->returnUrl,
            $session->cancelUrl,
        ));

        return GatewayStart::redirect($order->approveUrl, $order->id);
    }

    /**
     * @param array<string, string|bool> $settings
     */
    public static function account(array $settings): PayPalAccount
    {
        $text = static fn(string $key): string => \is_string($settings[$key] ?? null) ? trim($settings[$key]) : '';

        return new PayPalAccount($text('client_id'), $text('client_secret'), ($settings['sandbox'] ?? false) === true, $text('webhook_id'));
    }

    public static function decimals(string $currency): ?int
    {
        return self::CURRENCIES[$currency] ?? null;
    }
}
