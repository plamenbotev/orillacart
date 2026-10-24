<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Admin;

use OrillaCart\Shared\Http\Security\ClientAddress;
use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;

/**
 * Whether the shop knows its visitors apart behind a proxy or CDN: requests that come through one it does not
 * trust all count as one visitor, so its rate limits refuse everyone once a few have ordered.
 */
final readonly class ProxyHealth implements HealthCheck
{
    public static function id(): string
    {
        return 'proxy';
    }

    public static function label(): string
    {
        return __('Visitors behind a proxy or CDN', 'orillacart');
    }

    public function check(): HealthStatus
    {
        if (!ClientAddress::proxiedUntrusted($_SERVER)) {
            return HealthStatus::good(__('The shop tells its visitors apart', 'orillacart'));
        }

        return HealthStatus::recommended(
            __('The site is behind a proxy or CDN the shop does not trust', 'orillacart'),
            \sprintf(
                /* translators: %s: the proxy's address */
                __('Requests come through %s, so the shop counts every visitor as one and its limits (checkout, coupons, sign-ups) may refuse customers at busy times. Name the proxy in wp-config.php: define(\'ORILLACART_TRUSTED_PROXIES\', \'its addresses or ranges, such as 10.0.0.0/8\'); for Cloudflare, its published ranges.', 'orillacart'),
                \is_string($_SERVER['REMOTE_ADDR'] ?? null) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '?',
            ),
        );
    }

    public function info(): array
    {
        return ['behind_untrusted_proxy' => ['label' => __('Behind a proxy the shop does not trust', 'orillacart'), 'value' => ClientAddress::proxiedUntrusted($_SERVER) ? __('yes', 'orillacart') : __('no', 'orillacart')]];
    }
}
