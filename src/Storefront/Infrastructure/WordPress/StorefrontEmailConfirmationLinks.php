<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Checkout\Application\EmailConfirmationLinks;

/** A guest's email is confirmed on the shop's checkout page (?orillacart_email=TOKEN), on whatever device opens it. */
final readonly class StorefrontEmailConfirmationLinks implements EmailConfirmationLinks
{
    public const string QUERY = 'orillacart_email';

    public function __construct(private ShopLinks $links) {}

    public function url(#[\SensitiveParameter] string $token): string
    {
        // In the language the email is written in (design 51).
        return add_query_arg(self::QUERY, rawurlencode($token), $this->links->pages()['checkout'] ?? home_url('/'));
    }
}
