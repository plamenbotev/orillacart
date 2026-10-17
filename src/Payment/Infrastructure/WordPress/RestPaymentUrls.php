<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\WordPress;

use OrillaCart\Payment\Application\PaymentUrls;
use OrillaCart\Shared\Http\Routing\RouteCollection;

/**
 * The payment routes of the plugin's REST API, as absolute addresses.
 */
final readonly class RestPaymentUrls implements PaymentUrls
{
    public function returnUrl(string $gateway): string
    {
        return self::url($gateway, 'return');
    }

    public function cancelUrl(string $gateway): string
    {
        return self::url($gateway, 'cancel');
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
