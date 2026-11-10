<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Cart\Domain\Cart;
use OrillaCart\Shared\Domain\InvalidFields;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Http\Security\CsrfProtection;
use OrillaCart\Shared\Http\SiteCookies;
use OrillaCart\Shared\I18n\Translations;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;

/**
 * "Add to cart" in a browser without JavaScript: the product form posts to the cart page, which adds the item
 * and shows the cart. With JavaScript the storefront script sends the same fields to the REST API instead. A form
 * another site posts adds nothing: the browser names the page it came from (Origin, else Referer), and it must be
 * the site's own.
 */
#[AsHook('template_redirect', priority: 0)]
final readonly class AddToCartWithoutScript
{
    public const string FIELD = 'orillacart_add_to_cart';

    public function __construct(
        private CartService $carts,
        private Shopper $shopper,
        private ShopLinks $links,
        private Translations $translations,
        private CsrfProtection $csrf,
    ) {}

    public function __invoke(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST[self::FIELD])) {
            return;
        }
        if (!self::fromThisSite($_SERVER, $this->csrf)) {
            wp_die(esc_html__('Add the product to your cart from its page in this shop.', 'orillacart'), esc_html__('Not added to the cart', 'orillacart'), ['response' => 403, 'back_link' => true]);
        }
        $fields = self::fields();
        try {
            $result = $this->carts->add($this->shopper->cart(), new AddToCart(
                (int) ($fields['product_id'][0] ?? 0),
                self::ids($fields['option_ids'] ?? []),
                max(1, min(Cart::MAX_QUANTITY, (int) ($fields['quantity'][0] ?? 1))),
                \array_slice(self::ids($fields['bundle_choices'] ?? []), 0, 100),
                self::answers($fields),
            ));
        } catch (InvalidFields $e) {
            wp_die(esc_html(implode(' ', array_map(fn(array $violation): string => $this->translations->text($violation['message']), $e->violations))), esc_html__('Not added to the cart', 'orillacart'), ['response' => 422, 'back_link' => true]);
        } catch (RuleViolation|NotFound $e) {
            wp_die(esc_html($this->translations->text($e->text)), esc_html__('Not added to the cart', 'orillacart'), ['response' => 422, 'back_link' => true]);
        }
        // A new cart's token, or again the one the browser has, for as long as the shop keeps carts.
        $token = $result->newToken ?? $this->shopper->cart()->token;
        if ($token !== null) {
            $days = new \DateInterval(CartService::KEEP_FOR)->d;
            setcookie(CartOwner::COOKIE, $token, ['expires' => time() + $days * DAY_IN_SECONDS, 'path' => SiteCookies::path(), 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        }
        wp_safe_redirect($this->links->pages()['cart'] ?? home_url('/'), 303);
        exit;
    }

    /**
     * Whether the browser says the form was on one of the site's pages: by its Origin, else by the origin of its
     * Referer. A request with neither is taken at its word: browsers send Origin with every form they post. An
     * Origin of "null" is what a page under `Referrer-Policy: no-referrer` sends, and a sandboxed frame or a file
     * too: it goes only where the browser also says the request is the site's own (`Sec-Fetch-Site: same-origin`).
     *
     * @param array<array-key, mixed> $server
     */
    public static function fromThisSite(array $server, CsrfProtection $csrf): bool
    {
        $origin = $server['HTTP_ORIGIN'] ?? null;
        if (\is_string($origin) && $origin !== '') {
            if ($origin === 'null') {
                return ($server['HTTP_SEC_FETCH_SITE'] ?? null) === 'same-origin';
            }

            return $csrf->allowsOrigin($origin);
        }
        $referer = $server['HTTP_REFERER'] ?? null;
        if (!\is_string($referer) || $referer === '') {
            return true;
        }
        $parts = parse_url($referer);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return $csrf->allowsOrigin($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
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
     * The answers to the product's forms, named personalisation.<field id>; the cart reads only the fields asked.
     *
     * @param array<string, list<string>> $fields
     *
     * @return array<string, string>
     */
    private static function answers(array $fields): array
    {
        $answers = [];
        foreach ($fields as $name => $values) {
            if (str_starts_with($name, 'personalisation.') && \count($answers) < 100) {
                $answers[substr($name, \strlen('personalisation.'))] = $values[0] ?? '';
            }
        }

        return $answers;
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
