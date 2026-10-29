<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

/** A site in one language: each category is only itself. */
final readonly class UntranslatedCategories implements CategoryTranslations
{
    public function of(int $categoryId): array
    {
        return [$categoryId];
    }
}
