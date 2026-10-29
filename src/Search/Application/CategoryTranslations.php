<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

/**
 * A product category with its translations, on a site in several languages (design 51): the filters chosen for
 * it hold in every language, as its deletion does.
 */
interface CategoryTranslations
{
    /**
     * @return non-empty-list<int> the category first, then its translations
     */
    public function of(int $categoryId): array;
}
