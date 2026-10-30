<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Cart\Application\CartLinks;
use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Shared\Http\Security\ClientAddress;
use OrillaCart\Shared\Http\Security\RateLimiter;
use OrillaCart\Shared\Http\SiteCookies;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;

/**
 * The link in a cart reminder: brings the visitor's cart back to this browser (?orillacart_cart_link=its token),
 * puts the coupon the reminder offered in the cart (?orillacart_coupon=its code), and shows the cart without them.
 * A customer's cart is theirs after logging in, which the link leads through.
 */
#[AsHook('template_redirect', priority: 0)]
final readonly class CartFromReminder implements CartLinks
{
    public const string RECOVER = 'orillacart_cart_link';
    public const string COUPON = 'orillacart_coupon';

    public function __construct(
        private CartService $carts,
        private Shopper $shopper,
        private ShopLinks $links,
        private RateLimiter $limits,
    ) {}

    public function reminderUrl(?string $recoverToken, ?string $coupon): string
    {
        $cart = $this->links->pages()['cart'] ?? home_url('/');
        $args = array_filter([self::RECOVER => $recoverToken, self::COUPON => $coupon], static fn(?string $value): bool => $value !== null);
        $url = add_query_arg(array_map(rawurlencode(...), $args), $cart);

        return $recoverToken === null ? wp_login_url($url) : $url;
    }

    public function __invoke(): void
    {
        $token = $_GET[self::RECOVER] ?? null;
        $coupon = $_GET[self::COUPON] ?? null;
        if (!\is_string($token) && !\is_string($coupon)) {
            return;
        }
        if (\is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
            $this->give($this->carts->recover($token));
        }
        // Counted as codes typed at checkout are: the cart shows whether a code holds, so the link must not be a
        // way to try codes without end.
        if (\is_string($coupon) && $this->limits->hit('coupons', 'ip:' . ClientAddress::of($_SERVER)) === null) {
            $code = mb_substr(sanitize_text_field(wp_unslash($coupon)), 0, 100);
            $this->give($this->carts->offerCoupon($this->shopper->cart(), $code));
        }
        wp_safe_redirect(remove_query_arg([self::RECOVER, self::COUPON]), 303);
        exit;
    }

    /** The cart's token to the browser, and to the rest of this request. */
    private function give(?string $token): void
    {
        if ($token === null) {
            return;
        }
        $days = new \DateInterval(CartService::KEEP_FOR)->d;
        setcookie(CartOwner::COOKIE, $token, ['expires' => time() + $days * DAY_IN_SECONDS, 'path' => SiteCookies::path(), 'domain' => SiteCookies::domain(), 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[CartOwner::COOKIE] = $token;
    }
}
