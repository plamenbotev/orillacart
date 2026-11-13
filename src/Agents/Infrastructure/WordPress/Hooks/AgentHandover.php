<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Infrastructure\WordPress\Hooks;

use OrillaCart\Agents\Application\AgentCheckouts;
use OrillaCart\Agents\Infrastructure\WordPress\WordPressAgentEndpoints;
use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Shared\Http\SiteCookies;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Infrastructure\WordPress\ShopLinks;

/**
 * A session's continue_url (design 75): gives its cart to this browser and opens the shop's checkout, filled with
 * the buyer and address the agent gave (AgentCheckoutPrefills), for the buyer to review and pay. It works while the
 * session is open and as often as it is opened: the agent goes on changing the same cart, and a link preview that
 * opens it takes nothing from the buyer. A link that is not the session's, or a session that is over, opens the
 * shop.
 */
#[AsHook('template_redirect', priority: 0)]
final readonly class AgentHandover
{
    public function __construct(
        private AgentCheckouts $checkouts,
        private WordPressAgentEndpoints $endpoints,
        private ShopLinks $links,
    ) {}

    public function __invoke(): void
    {
        $id = $_GET[WordPressAgentEndpoints::SESSION] ?? null;
        $signature = $_GET[WordPressAgentEndpoints::SIGNATURE] ?? null;
        if (!\is_string($id) || !\is_string($signature)) {
            return;
        }
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        $token = $this->endpoints->verify($id, $signature) ? $this->checkouts->handover($id) : null;
        if ($token === null) {
            wp_safe_redirect(home_url('/'), 303);
            exit;
        }
        $days = new \DateInterval(CartService::KEEP_FOR)->d;
        setcookie(CartOwner::COOKIE, $token, ['expires' => time() + $days * DAY_IN_SECONDS, 'path' => SiteCookies::path(), 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[CartOwner::COOKIE] = $token;
        wp_safe_redirect($this->links->pages()['checkout'] ?? home_url('/'), 303);
        exit;
    }
}
