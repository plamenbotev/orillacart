<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

/**
 * One way a product is sold: a variation, or the product itself (with the product's id) when it has none.
 */
final readonly class DocumentVariant
{
    /**
     * @param string|null          $price   today's price before tax and options; null when shoppers ask for the price
     * @param list<DocumentOption> $options the variation's options; none for the product itself, whose options are the document's
     */
    public function __construct(
        public int $id,
        public ?string $price,
        public bool $inStock,
        public array $options = [],
    ) {}
}
