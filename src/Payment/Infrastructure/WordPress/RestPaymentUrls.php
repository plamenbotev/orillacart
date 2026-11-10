<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\WordPress;

use OrillaCart\Extension\Payment\ConfirmsNotifications;
use OrillaCart\Payment\Application\PaymentOutcomes;
use OrillaCart\Payment\Application\PaymentUrls;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\Security\Signatures;
use Psr\Clock\ClockInterface;

/**
 * The payment routes of the plugin's REST API, as absolute addresses. The return and cancel addresses carry the
 * payment and the day, signed (PaymentOutcomes::returnQuery()).
 */
final readonly class RestPaymentUrls implements PaymentUrls
{
    public function __construct(
        private Signatures $signatures,
        private ClockInterface $clock,
    ) {}

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
        return add_query_arg(PaymentOutcomes::returnQuery($this->signatures, $gateway, $paymentId, $this->clock->now()), $url);
    }

    /** With the shop's key for the gateway, which a gateway that confirms its notifications needs. */
    public function notificationUrl(string $gateway): string
    {
        return add_query_arg([ConfirmsNotifications::NOTIFICATION_KEY => PaymentOutcomes::notificationKey($this->signatures, $gateway)], self::url($gateway, 'webhook'));
    }

    private static function url(string $gateway, string $action): string
    {
        return rest_url(RouteCollection::REST_NAMESPACE . "/payments/{$gateway}/{$action}");
    }
}
