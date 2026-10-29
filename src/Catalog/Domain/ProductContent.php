<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

/**
 * The content side of products and their variations, which WordPress keeps as posts: names, whether they are
 * published, and the posts of variations, which are children of their product's.
 */
interface ProductContent
{
    /** Whether a product post with this id exists, in any status. */
    public function exists(int $productId): bool;

    public function isPublished(int $productId): bool;

    public function name(int $productId): string;

    /**
     * Product posts whose name holds the words, in every language the site has: drafts and private ones too,
     * the newest first. A translation's post is found by its own name.
     *
     * @return list<int>
     */
    public function idsNamed(string $words, int $limit): array;

    /**
     * The product's category and brand ids, each with every term above it, so a rule about a category holds
     * for its subcategories.
     *
     * @return array{categories: list<int>, brands: list<int>}
     */
    public function termIds(int $productId): array;

    /**
     * Ids of the products in any of the categories, or the categories below them, or in any of the brands.
     *
     * @param list<int> $categoryIds
     * @param list<int> $brandIds
     *
     * @return list<int>
     */
    public function productIdsInTerms(array $categoryIds, array $brandIds): array;

    /**
     * Makes the post of a new variation of the product.
     *
     * @return int its id, which the variation shares
     */
    public function createVariation(int $productId, string $name): int;

    /** Removes a variation's post, with nothing left in the trash. */
    public function deleteVariation(int $variationId): void;

    /**
     * A draft copy of the product's post, "(copy)" after its name: its texts, terms, picture and gallery.
     *
     * @return int the copy's id
     */
    public function copy(int $productId): int;
}
