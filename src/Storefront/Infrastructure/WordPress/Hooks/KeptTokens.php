<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Ordering\Application\ReceiptLinks;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontAccountLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontOrderLinks;

/**
 * A receipt's link, and the one that finishes a new account, carry a token that opens the order or the account.
 * Opened, the token goes into a cookie of the page's own path for KEPT_FOR, which scripts cannot read, and the
 * browser goes on to the address without it (?orillacart_order=kept): the browser's history, the analytics tags on
 * the page and whatever else reads the address never see the token. The page reads it back with token().
 */
#[AsHook('template_redirect', priority: 0)]
final readonly class KeptTokens
{
    /** What the address says in place of the token. */
    public const string KEPT = 'kept';
    /** How long the browser keeps a token, in seconds: the page shows the order for so long after its link was opened. */
    public const int KEPT_FOR = 3600;

    /** The cookie each address's token goes to. */
    private const array COOKIES = [
        StorefrontOrderLinks::RECEIPT_QUERY => 'orillacart_receipt',
        StorefrontAccountLinks::CONFIRM_QUERY => 'orillacart_account_link',
    ];

    /** What a token of each address looks like. */
    private const array PATTERNS = [
        StorefrontOrderLinks::RECEIPT_QUERY => ReceiptLinks::PATTERN,
        StorefrontAccountLinks::CONFIRM_QUERY => '[a-f0-9]{64}',
    ];

    public function __invoke(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || headers_sent()) {
            return;
        }
        foreach (self::COOKIES as $query => $cookie) {
            $token = $_GET[$query] ?? null;
            if (!\is_string($token) || preg_match('/^' . self::PATTERNS[$query] . '$/', $token) !== 1) {
                continue;
            }
            $path = parse_url(\is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', \PHP_URL_PATH);
            setcookie($cookie, $token, ['expires' => time() + self::KEPT_FOR, 'path' => \is_string($path) && $path !== '' ? $path : '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
            nocache_headers();
            header('Referrer-Policy: no-referrer');
            wp_safe_redirect(add_query_arg($query, self::KEPT), 303);
            exit;
        }
    }

    /**
     * The token the address carries; for an address that says KEPT, the one this browser keeps for it, or '' once
     * it kept it for KEPT_FOR. Null for an address without one.
     */
    public static function token(string $query): ?string
    {
        $value = $_GET[$query] ?? null;
        if (!\is_string($value)) {
            return null;
        }
        $value = sanitize_text_field(wp_unslash($value));
        if ($value !== self::KEPT || !isset(self::COOKIES[$query])) {
            return $value;
        }
        $kept = $_COOKIE[self::COOKIES[$query]] ?? null;

        return \is_string($kept) ? sanitize_text_field(wp_unslash($kept)) : '';
    }
}
