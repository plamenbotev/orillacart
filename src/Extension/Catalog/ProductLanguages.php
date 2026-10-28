<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/**
 * Products in several languages, where Polylang or WPML translates them. A translation is its own WordPress post
 * holding the product's text in another language; the product, with its price, stock and orders, is the original.
 * Since version 17.
 */
interface ProductLanguages
{
    /** @return list<string> the codes of the site's content languages, such as "de"; empty with one language */
    public function languages(): array;

    /** The product a post is: the post itself, or the original when it is a translation. */
    public function product(int $postId): int;

    /**
     * The post that shows the product in the request's language, or in the locale's (such as de_DE, for an email
     * in a customer's language): its published translation, else the product itself.
     */
    public function shown(int $productId, ?string $locale = null): int;
}
