<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Page caches keep a page apart for each currency a shopper chose (ShopperCurrency's cookie), where they can be
 * told so: LiteSpeed Cache and WP Rocket by their filters, WP Super Cache by its action, which keeps the cookie in
 * its settings. A shop in one currency tells them nothing, and WP Super Cache forgets the cookie again. Cloudflare's
 * APO is told by a Cache Rule on the cookie (readme.txt).
 */
#[AsHook('litespeed_vary_cookies', method: 'cookies', filter: true)]
#[AsHook('rocket_cache_dynamic_cookies', method: 'cookies', filter: true)]
#[AsHook('admin_init', method: 'superCache')]
final readonly class CurrencyCookieInCaches
{
    public function __construct(private Currencies $currencies) {}

    /**
     * @param mixed $cookies the names the cache varies by
     *
     * @return array<array-key, mixed>
     */
    public function cookies(mixed $cookies): array
    {
        $cookies = \is_array($cookies) ? $cookies : [];
        if ($this->severalCurrencies() && !\in_array(ShopperCurrency::COOKIE, $cookies, true)) {
            $cookies[] = ShopperCurrency::COOKIE;
        }

        return $cookies;
    }

    public function superCache(): void
    {
        if (!\function_exists('wpsc_add_cookie')) {
            return;
        }
        do_action($this->severalCurrencies() ? 'wpsc_add_cookie' : 'wpsc_delete_cookie', ShopperCurrency::COOKIE);
    }

    private function severalCurrencies(): bool
    {
        return \count($this->currencies->codes()) > 1;
    }
}
