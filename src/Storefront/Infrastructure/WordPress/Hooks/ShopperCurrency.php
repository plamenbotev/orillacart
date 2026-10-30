<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Settings\Application\CurrencyChoice;
use OrillaCart\Shared\WordPress\AsHook;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * The currency the shopper chose, kept in a cookie for a year: the currency switcher links to any page with
 * ?orillacart_currency=USD, which keeps it and shows the page without it. The shop's own screens price in the
 * shop's currency, whatever the shopper chose. The cookie is set at the site's own path, as the cart's is, so
 * two shops of a network in folders of one domain each keep the shopper's choice for themselves.
 */
#[AsHook('template_redirect', priority: 0)]
final readonly class ShopperCurrency implements CurrencyChoice
{
    public const string COOKIE = 'orillacart_currency';
    public const string PARAMETER = 'orillacart_currency';

    /**
     * @param \Closure(): Currencies $currencies late, as the currencies ask this for the shopper's choice
     */
    public function __construct(
        #[AutowireServiceClosure(Currencies::class)]
        private \Closure $currencies,
    ) {}

    public function chosen(): ?string
    {
        if (is_admin() && !wp_doing_ajax()) {
            return null;
        }
        $chosen = $_COOKIE[self::COOKIE] ?? null;

        return \is_string($chosen) && preg_match('/^[A-Z]{3}$/', $chosen) === 1 ? $chosen : null;
    }

    public function __invoke(): void
    {
        $asked = $_GET[self::PARAMETER] ?? null;
        if (!\is_string($asked)) {
            return;
        }
        $currencies = ($this->currencies)();
        if (preg_match('/^[A-Z]{3}$/', $asked) === 1 && $currencies->isOffered($asked)) {
            setcookie(self::COOKIE, $asked, ['expires' => time() + YEAR_IN_SECONDS, 'path' => CartCookie::path(), 'domain' => CartCookie::domain(), 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
            $_COOKIE[self::COOKIE] = $asked;
        }
        wp_safe_redirect(remove_query_arg(self::PARAMETER), 303);
        exit;
    }
}
