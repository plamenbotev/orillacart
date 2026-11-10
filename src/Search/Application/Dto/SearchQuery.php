<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

/**
 * What a shopper looks for. Values within a filter are alternatives, filters all hold; options, price and stock
 * hold for one and the same variant.
 */
final readonly class SearchQuery
{
    /** The most characters of words a search takes, */
    public const int MAX_TEXT_LENGTH = 100;
    /** and words: each one is another condition. */
    public const int MAX_WORDS = 8;
    /** The most brands one search is for. */
    public const int MAX_BRANDS = 20;

    /**
     * @param list<int>          $categoryIds products in one of the categories or below them
     * @param list<int>          $brandIds
     * @param list<int>          $tagIds      products with one of the tags
     * @param list<OptionFilter> $options
     * @param string|null        $priceMin    today's price before tax, from
     * @param string|null        $priceMax    up to and including
     * @param int                $limit       how many products to list; 0 for the total and the counts only
     * @param bool               $facets      count what each filter would leave
     * @param string|null        $locale      the language shown, on a site in several: products are found by their words in it
     * @param bool               $untranslated also products without a translation into it, by their own words
     * @param bool               $count       count the matches in all; false for a page whose total is known already,
     *                                        which then comes back as 0
     */
    public function __construct(
        public ?string $text = null,
        public array $categoryIds = [],
        public array $brandIds = [],
        public array $options = [],
        public ?string $priceMin = null,
        public ?string $priceMax = null,
        public bool $inStockOnly = false,
        public bool $featuredOnly = false,
        public bool $onSaleOnly = false,
        public SearchSort $sort = SearchSort::Order,
        public int $offset = 0,
        public int $limit = 12,
        public bool $facets = false,
        public array $tagIds = [],
        public ?string $locale = null,
        public bool $untranslated = true,
        public bool $count = true,
    ) {}

    /** Within what a search takes: its first MAX_WORDS words up to MAX_TEXT_LENGTH characters, its first MAX_BRANDS brands. */
    public function bounded(): self
    {
        $text = $this->text === null ? null : self::words($this->text);
        if ($text === $this->text && \count($this->brandIds) <= self::MAX_BRANDS) {
            return $this;
        }

        return clone($this, ['text' => $text, 'brandIds' => \array_slice($this->brandIds, 0, self::MAX_BRANDS)]);
    }

    /** The first MAX_WORDS words of what a shopper typed, up to MAX_TEXT_LENGTH characters. */
    public static function words(string $text): string
    {
        $words = preg_split('/\s+/u', trim(mb_substr($text, 0, self::MAX_TEXT_LENGTH)), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? '' : implode(' ', \array_slice($words, 0, self::MAX_WORDS));
    }

    public function inLanguage(?string $locale): self
    {
        return clone($this, ['locale' => $locale]);
    }

    /** What the matches depend on: the query without its order, page or counts. */
    public function matching(): self
    {
        return clone($this, ['sort' => SearchSort::Order, 'offset' => 0, 'limit' => 0, 'facets' => false, 'count' => true]);
    }

    /** Its page of products only, the total and the filters' counts known already. */
    public function pageOnly(): self
    {
        return clone($this, ['facets' => false, 'count' => false]);
    }
}
