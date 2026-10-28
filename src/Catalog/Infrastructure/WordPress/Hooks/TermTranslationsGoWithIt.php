<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Hooks;

use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductBrandTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductCategoryTaxonomy;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductTagTaxonomy;
use OrillaCart\Shared\I18n\ContentLanguages;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * A category, brand or tag deleted in one language is deleted in all of them (design 51): its translations go
 * with it, and each language's products lose it. Before WordPress deletes the term, while Polylang and WPML still
 * know its translations. Each translation's delete runs this again: the group is remembered, so none is deleted
 * twice and the first term is not deleted from inside its own delete.
 */
#[AsHook('pre_delete_term', method: 'deleting', arguments: 2)]
final class TermTranslationsGoWithIt
{
    private const array TAXONOMIES = [ProductCategoryTaxonomy::NAME, ProductBrandTaxonomy::NAME, ProductTagTaxonomy::NAME];

    /** @var array<int, true> the terms being deleted with their group, by id */
    private array $deleting = [];

    public function __construct(private readonly ContentLanguages $languages) {}

    public function deleting(int $termId, string $taxonomy): void
    {
        if (isset($this->deleting[$termId]) || !\in_array($taxonomy, self::TAXONOMIES, true) || !$this->languages->multilingual()) {
            return;
        }
        $group = array_values($this->languages->termTranslations($termId, $taxonomy));
        foreach ([$termId, ...$group] as $id) {
            $this->deleting[$id] = true;
        }
        try {
            foreach ($group as $translation) {
                if ($translation !== $termId && term_exists($translation, $taxonomy) !== null) {
                    wp_delete_term($translation, $taxonomy);
                }
            }
        } finally {
            foreach ([$termId, ...$group] as $id) {
                unset($this->deleting[$id]);
            }
        }
    }
}
