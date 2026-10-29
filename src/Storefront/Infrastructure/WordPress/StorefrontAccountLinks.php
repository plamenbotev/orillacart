<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Customers\Application\AccountLinks;
use OrillaCart\Storefront\Application\ShopPagesService;

/**
 * A new account is finished on the account page (?orillacart_account=TOKEN); logging in and lost passwords are
 * WordPress's, coming back to the account page.
 */
final readonly class StorefrontAccountLinks implements AccountLinks
{
    public const string CONFIRM_QUERY = 'orillacart_account';

    public function __construct(
        private ShopPagesService $pages,
        private ShopLinks $links,
    ) {}

    public function accountUrl(): string
    {
        return $this->accountPage();
    }

    public function confirmUrl(string $token): string
    {
        return add_query_arg(self::CONFIRM_QUERY, rawurlencode($token), $this->accountPage());
    }

    public function loginUrl(): string
    {
        return wp_login_url($this->accountPage());
    }

    public function lostPasswordUrl(): string
    {
        return wp_lostpassword_url($this->accountPage());
    }

    private function accountPage(): string
    {
        // In the language the link is written in: an email's, the customer's (design 51).
        return $this->links->pageUrl($this->pages->pages()->account) ?? home_url('/');
    }
}
