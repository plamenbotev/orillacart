<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\WordPress;

use OrillaCart\Payment\Application\PaymentOutcomes;
use OrillaCart\Payment\Application\PaymentUrls;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\Security\Signatures;

/**
 * The payment routes of the plugin's REST API, as absolute addresses.
 */
final readonly class RestPaymentUrls implements PaymentUrls
{
    public function __construct(private Signatures $signatures) {}

    public function returnUrl(string $gateway, int $paymentId): string
    {
        return $this->signed(self::url($gateway, 'return'), $gateway, $paymentId);
    }

    public function cancelUrl(string $gateway, int $paymentId): string
    {
        return $this->signed(self::url($gateway, 'cancel'), $gateway, $paymentId);
    }

    private function signed(string $url, string $gateway, int $paymentId): string
    {
        return add_query_arg(['payment' => $paymentId, 'sig' => $this->signatures->sign(PaymentOutcomes::RETURN_SIGNATURE, "{$gateway}:{$paymentId}")], $url);
    }

    public function notificationUrl(string $gateway): string
    {
        return self::url($gateway, 'webhook');
    }

    private static function url(string $gateway, string $action): string
    {
        return rest_url(RouteCollection::REST_NAMESPACE . "/payments/{$gateway}/{$action}");
    }
}
