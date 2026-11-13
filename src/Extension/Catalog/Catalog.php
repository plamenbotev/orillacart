<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Catalog;

/**
 * The shop's products as shoppers find them (API 29): the shop's own listing, with its search index, filters and
 * their counts, sorts and pages, for an add-on's landing page or its own way to browse. Only what is on sale and
 * not behind a password, in the shopper's language and currency, priced as the shop shows prices to them; so it
 * takes no permission, as it gives nothing a visitor does not already see.
 */
interface Catalog
{
    public function list(CatalogQuery $query): CatalogPage;
}
