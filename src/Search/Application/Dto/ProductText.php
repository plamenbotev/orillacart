<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

/**
 * What the site's content says of a published product: its words, place in the shop, where it is filed and how it is tagged.
 */
final readonly class ProductText
{
    /**
     * @param string             $text       the excerpt and the content as plain text
     * @param list<DocumentTerm> $categories the product's categories and every category above them
     * @param list<DocumentTerm> $brands
     * @param list<DocumentTerm> $tags
     * @param string|null        $locale       the post's language, on a site in several
     * @param list<DocumentText> $translations its translations' words, those published
     */
    public function __construct(
        public string $title,
        public string $text,
        public int $menuOrder,
        public \DateTimeImmutable $publishedAt,
        public array $categories,
        public array $brands,
        public array $tags = [],
        public ?string $locale = null,
        public array $translations = [],
    ) {}
}
