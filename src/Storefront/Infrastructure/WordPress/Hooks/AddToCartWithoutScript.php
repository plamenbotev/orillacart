<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Cart\Domain\Cart;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;

/**
 * "Add to cart" in a browser without JavaScript: the product form posts to the cart page, which adds the item
 * and shows the cart. With JavaScript the storefront script sends the same fields to the REST API instead.
 */
#[AsHook('template_redirect', priority: 0)]
final readonly class AddToCartWithoutScript
{
    public const string FIELD = 'orillacart_add_to_cart';

    public function __construct(
        private CartService $carts,
        private Shopper $shopper,
        private ShopLinks $links,
    ) {}

    public function __invoke(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST[self::FIELD])) {
            return;
        }
        $fields = self::fields();
        try {
            $result = $this->carts->add($this->shopper->cart(), new AddToCart(
                (int) ($fields['product_id'][0] ?? 0),
                self::ids($fields['option_ids'] ?? []),
                max(1, min(Cart::MAX_QUANTITY, (int) ($fields['quantity'][0] ?? 1))),
                \array_slice(self::ids($fields['bundle_choices'] ?? []), 0, 100),
            ));
        } catch (RuleViolation|NotFound $e) {
            wp_die(esc_html($e->getMessage()), esc_html__('Not added to the cart', 'orillacart'), ['response' => 422, 'back_link' => true]);
        }
        // A new cart's token, or again the one the browser has, for as long as the shop keeps carts.
        $token = $result->newToken ?? $this->shopper->cart()->token;
        if ($token !== null) {
            $days = new \DateInterval(CartService::KEEP_FOR)->d;
            setcookie(CartOwner::COOKIE, $token, ['expires' => time() + $days * DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        }
        wp_safe_redirect($this->links->pages()['cart'] ?? home_url('/'), 303);
        exit;
    }

    /**
     * The form's fields as it sent them: a field the form repeats, such as one option per attribute, keeps every
     * value, which $_POST keeps only for names ending in [].
     *
     * @return array<string, list<string>>
     */
    private static function fields(): array
    {
        $fields = [];
        foreach (explode('&', (string) file_get_contents('php://input')) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $fields[urldecode($name)][] = urldecode($value);
        }

        return $fields;
    }

    /**
     * @param list<string> $values
     *
     * @return list<int>
     */
    private static function ids(array $values): array
    {
        return array_values(array_filter(array_map(intval(...), array_filter($values, ctype_digit(...))), static fn(int $id): bool => $id > 0));
    }
}
