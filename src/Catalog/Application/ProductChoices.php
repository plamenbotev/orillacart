<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\ProductChoice;
use OrillaCart\Catalog\Application\Dto\ProductChoiceQuery;
use OrillaCart\Catalog\Domain\ProductContent;

/**
 * The products the admin's pickers offer (upsells, a bundle's items, a coupon's or a block's product): each
 * product once, by its own id, found by its name in any of the site's languages and named in the admin's. A
 * translation's post is never offered in the product's place, which would split what the shop keeps of it.
 */
final readonly class ProductChoices
{
    private const int SHOWN = 20;

    public function __construct(
        private ProductContent $content,
        private ProductTranslations $translations,
    ) {}

    /**
     * @param bool $publishedOnly for those who cannot edit products: drafts, private and scheduled ones are not
     *                            theirs to see
     *
     * @return list<ProductChoice>
     */
    public function find(ProductChoiceQuery $query, bool $publishedOnly = false): array
    {
        $ids = $query->ids();
        if ($ids === []) {
            $words = trim($query->search);
            if ($words === '') {
                return [];
            }
            // More posts than shown: a product's translations are found with it, and count once.
            $ids = \array_slice(array_values(array_unique($this->translations->originals($this->content->idsNamed($words, self::SHOWN * 4, $publishedOnly)))), 0, self::SHOWN);
        }

        return array_values(array_map(
            fn(int $id): ProductChoice => new ProductChoice($id, $this->content->name($this->translations->shown($id))),
            array_filter($ids, $publishedOnly ? $this->content->isPublished(...) : $this->content->exists(...)),
        ));
    }
}
