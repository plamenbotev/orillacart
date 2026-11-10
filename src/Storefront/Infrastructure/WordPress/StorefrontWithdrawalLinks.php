<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Returns\Application\WithdrawalLinks;
use OrillaCart\Storefront\Application\LegalSettingsService;

/** An order's withdrawal opens on the shop's withdrawal page (?orillacart_withdrawal=TOKEN), or the home page without one. */
final readonly class StorefrontWithdrawalLinks implements WithdrawalLinks
{
    public const string QUERY = 'orillacart_withdrawal';

    public function __construct(
        private LegalSettingsService $legal,
        private ShopLinks $links,
    ) {}

    public function url(#[\SensitiveParameter] string $token): string
    {
        // In the language the email is written in (design 51).
        $page = $this->links->pageUrl($this->legal->current()->withdrawalForm) ?? home_url('/');

        return add_query_arg(self::QUERY, rawurlencode($token), $page);
    }
}
