<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Extension\Catalog\ProductLanguages;
use OrillaCart\Shared\I18n\ContentLanguages;

/**
 * Which post of a product's translations is the product (design 51): the one with the shop's details, so price,
 * stock, SKU, variations and orders are always its, and a translation is the text in another language. A
 * translated post's id is taken back to the original wherever the shop looks the product up, and the original's
 * is taken to the translation wherever its text or address shows.
 */
final class ProductTranslations implements ProductLanguages
{
    /** @var array<int, int> the original by post id, for the request */
    private array $originals = [];

    public function __construct(
        private readonly ContentLanguages $languages,
        private readonly ProductRepository $products,
        private readonly ProductContent $content,
    ) {}

    public function multilingual(): bool
    {
        return $this->languages->multilingual();
    }

    public function languages(): array
    {
        return array_map(static fn($language): string => $language->code, $this->languages->languages());
    }

    public function product(int $postId): int
    {
        return $this->original($postId);
    }

    /** The product a post is: itself, or the original when it is a translation. */
    public function original(int $postId): int
    {
        if ($postId <= 0 || !$this->languages->multilingual()) {
            return $postId;
        }
        if (isset($this->originals[$postId])) {
            return $this->originals[$postId];
        }
        $translations = $this->languages->postTranslations($postId);
        $original = $postId;
        if (\count($translations) > 1) {
            $products = $this->products->existing(array_values($translations));
            if (\in_array($postId, $products, true)) {
                $original = $postId;
            } elseif ($products !== []) {
                // Several only when translations were linked after each was a product: the oldest stays the product.
                $original = min($products);
            } else {
                $default = $this->languages->default();
                $original = $default !== null ? ($translations[$default->code] ?? $postId) : $postId;
            }
        }
        // The same for every post of the group.
        foreach ([...$translations, $postId] as $id) {
            $this->originals[$id] = $original;
        }

        return $original;
    }

    /**
     * @param list<int> $postIds
     *
     * @return list<int> in the same order, each taken back to its original
     */
    public function originals(array $postIds): array
    {
        return array_map($this->original(...), $postIds);
    }

    /** Whether the post is a translation of another one, which is the product. */
    public function isTranslation(int $postId): bool
    {
        return $this->original($postId) !== $postId;
    }

    /**
     * The post whose text and address show the product: its published translation in the request's language, or
     * in the locale's (an email in a customer's language); the product itself when it has none.
     */
    public function shown(int $productId, ?string $locale = null): int
    {
        if ($productId <= 0 || !$this->languages->multilingual()) {
            return $productId;
        }
        $language = $locale !== null ? $this->languages->forLocale($locale) : $this->languages->current();
        if ($language === null) {
            return $productId;
        }
        $translation = $this->languages->postTranslations($this->original($productId))[$language->code] ?? null;

        return $translation !== null && $this->content->isPublished($translation) ? $translation : $productId;
    }
}
