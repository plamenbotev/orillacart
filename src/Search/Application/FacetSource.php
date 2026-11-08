<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Search\Application\Dto\DocumentOption;

/**
 * Something besides a product's attributes that shoppers filter the catalogue by: an extension's field on the
 * product, say (design 56). Its values go into the product's search document as options of an attribute named
 * after it, so a search filter picks it as it picks an attribute, on either engine.
 */
interface FacetSource
{
    public const string TAG = 'orillacart.facet_source';

    /**
     * The options of many products, read together.
     *
     * @param list<int> $productIds
     *
     * @return array<int, list<DocumentOption>> by product id; a product with none is left out
     */
    public function options(array $productIds): array;

    /**
     * The names a filter chooses from, as the attributes' names are listed.
     *
     * @return list<string>
     */
    public function attributeNames(): array;
}
