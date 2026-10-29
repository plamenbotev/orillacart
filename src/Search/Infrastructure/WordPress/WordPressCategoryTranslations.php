<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Search\Application\CategoryTranslations;
use OrillaCart\Shared\I18n\ContentLanguages;

/** A product category's translations, as Polylang or WPML link them. */
final readonly class WordPressCategoryTranslations implements CategoryTranslations
{
    public function __construct(private ContentLanguages $languages) {}

    public function of(int $categoryId): array
    {
        $others = $this->languages->multilingual() ? array_values($this->languages->termTranslations($categoryId, ProductCategoryTaxonomy::NAME)) : [];

        return array_values(array_unique([$categoryId, ...$others]));
    }
}
