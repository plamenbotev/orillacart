<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

use OrillaCart\Shared\Domain\Money;

/**
 * A product flattened for search: what shoppers find it by, filter it by and sort it by. Search engines keep
 * documents in their own way. On a site in several languages its words are also in its translations' languages;
 * a shopper in a language finds it by those, or by its own where it has none there (design 51).
 */
final readonly class SearchDocument
{
    /**
     * @param list<string>          $skus       the product's and its variations'
     * @param list<DocumentTerm>    $categories with the categories above them
     * @param list<DocumentTerm>    $brands
     * @param list<DocumentOption>  $options    options of the whole product, which has no variations
     * @param non-empty-list<DocumentVariant> $variants
     * @param \DateTimeImmutable|null $priceChangesAt when a sale starts or ends, so the document is built again then
     * @param list<DocumentTerm>    $tags
     * @param string|null           $locale       the language of its own words, on a site in several
     * @param list<DocumentText>    $translations its words in other languages
     */
    public function __construct(
        public int $productId,
        public string $title,
        public string $text,
        public array $skus,
        public array $categories,
        public array $brands,
        public array $options,
        public array $variants,
        public bool $featured,
        public bool $onSale,
        public int $menuOrder,
        public \DateTimeImmutable $publishedAt,
        public ?\DateTimeImmutable $priceChangesAt = null,
        public array $tags = [],
        public ?string $locale = null,
        public array $translations = [],
    ) {}

    /**
     * The locales it has words in besides its own.
     *
     * @return list<string>
     */
    public function translatedInto(): array
    {
        return array_map(static fn(DocumentText $text): string => $text->locale, $this->translations);
    }

    /**
     * @return array{?string, ?string} the lowest and highest variant price; nulls when shoppers ask for the price
     */
    public function priceRange(): array
    {
        $prices = array_values(array_filter(array_map(static fn(DocumentVariant $variant): ?string => $variant->price, $this->variants), \is_string(...)));
        if ($prices === []) {
            return [null, null];
        }
        usort($prices, static fn(string $a, string $b): int => Money::of($a)->compare(Money::of($b)));

        return [$prices[0], $prices[\count($prices) - 1]];
    }

    public function inStock(): bool
    {
        return array_filter($this->variants, static fn(DocumentVariant $variant): bool => $variant->inStock) !== [];
    }
}
