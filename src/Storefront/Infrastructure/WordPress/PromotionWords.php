<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Coupons\Application\Dto\PromotionView;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\I18n\ContentLanguages;
use OrillaCart\Shared\View\TemplateRenderer;

/**
 * Promotions as the promotion blocks show them (templates/storefront/_promotion_words.html.twig): what each is for
 * by name (its products, categories and brands, the items buy-get gives, the gift) in the shopper's language
 * (design 51), and the bar for what the cart lacks most.
 */
final readonly class PromotionWords
{
    public function __construct(
        private ProductContent $content,
        private ProductTranslations $translations,
        private ShopLinks $links,
        private ContentLanguages $languages,
        private TemplateRenderer $templates,
    ) {}

    /**
     * The promotions the cart gets and could still get, as list items: the script trims them to each block's
     * settings, as does the block filled in on the server.
     *
     * @param list<PromotionView> $promotions
     */
    public function cart(array $promotions, bool $applied = true, ?int $count = null): string
    {
        $offers = 0;
        $shown = [];
        foreach ($promotions as $promotion) {
            if ($promotion->applied ? !$applied : ($count !== null && $offers++ >= $count)) {
                continue;
            }
            $shown[] = $this->words($promotion);
        }

        return $shown === [] ? '' : trim($this->templates->render('storefront/_promotions.html.twig', ['promotions' => $shown]));
    }

    /**
     * A product's promotions and their deals in words.
     *
     * @param list<PromotionView> $promotions
     */
    public function product(int $productId, array $promotions): string
    {
        return $promotions === [] ? '' : $this->templates->render('storefront/product-promotions.html.twig', [
            'id' => $productId,
            'promotions' => array_map($this->words(...), $promotions),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function words(PromotionView $promotion): array
    {
        $products = $this->names($promotion->productIds);
        $terms = $this->termNames([...$promotion->categoryIds, ...$promotion->brandIds]);
        $gift = null;
        if ($promotion->giftProductId !== null) {
            $gift = ['name' => $this->names([$promotion->giftProductId]), 'url' => $this->links->products([$promotion->giftProductId])[$promotion->giftProductId] ?? null];
        }

        return [
            'code' => $promotion->code,
            'automatic' => $promotion->automatic,
            'kind' => $promotion->kind,
            'description' => $promotion->description,
            'amount' => $promotion->amount,
            // A whole hundred percent off is free.
            'free' => Money::of($promotion->amount)->compare(Money::of('100')) === 0,
            'currency' => $promotion->currency,
            'min_subtotal' => $promotion->minSubtotal,
            'min_quantity' => $promotion->minQuantity,
            'buy' => $promotion->buyQuantity,
            'get' => $promotion->getQuantity,
            // What it is for, as the items to add are named: anything, products by name, or categories and brands
            // (with any products besides).
            'scope' => match (true) {
                $terms !== '' => ['kind' => 'terms', 'names' => self::joined([$terms, $products])],
                $products !== '' => ['kind' => 'products', 'names' => $products],
                default => ['kind' => 'all', 'names' => ''],
            },
            'given' => self::joined([$this->names($promotion->getProductIds), $this->termNames($promotion->getCategoryIds)]),
            'gift' => $gift,
            'applied' => $promotion->applied,
            'saved' => $promotion->saved !== null && !Money::of($promotion->saved)->isZero() ? $promotion->saved : null,
            'short' => $promotion->short,
            'units_short' => $promotion->unitsShort(),
            'needs_given' => $promotion->needsGiven,
            'bar' => $promotion->applied ? null : self::bar($promotion),
        ];
    }

    /**
     * The bar for what the cart lacks most: the order's size or the items; none when it lacks neither.
     *
     * @return array{money: bool, now: string, max: string, percent: int}|null
     */
    private static function bar(PromotionView $promotion): ?array
    {
        $money = null;
        if ($promotion->short !== null && $promotion->spent !== null && $promotion->minSubtotal !== null && !Money::of($promotion->minSubtotal)->isZero()) {
            $money = Money::of($promotion->spent)->tenThousandths / Money::of($promotion->minSubtotal)->tenThousandths;
        }
        $units = $promotion->unitsShort() > 0 ? $promotion->units / max(1, $promotion->neededUnits) : null;
        // Whole percents for the width, never full before it is reached.
        $percent = static fn(float $share): int => min(99, max(0, (int) floor($share * 100)));
        if ($money !== null && ($units === null || $money <= $units)) {
            return ['money' => true, 'now' => (string) $promotion->spent, 'max' => (string) $promotion->minSubtotal, 'percent' => $percent($money)];
        }

        return $units === null ? null : ['money' => false, 'now' => (string) $promotion->units, 'max' => (string) $promotion->neededUnits, 'percent' => $percent($units)];
    }

    /**
     * @param list<string> $names lists of names, some perhaps empty
     */
    private static function joined(array $names): string
    {
        return implode(', ', array_filter($names, static fn(string $name): bool => $name !== ''));
    }

    /**
     * @param list<int> $ids
     */
    private function names(array $ids): string
    {
        $names = [];
        foreach ($ids as $id) {
            if ($this->content->exists($id)) {
                $names[] = $this->content->name($this->translations->shown($id));
            }
        }

        return implode(', ', $names);
    }

    /**
     * Categories' and brands' names in the language shown: from their translation, else their own.
     *
     * @param list<int> $ids
     */
    private function termNames(array $ids): string
    {
        $code = $this->languages->current()?->code;
        $names = [];
        foreach ($ids as $id) {
            $term = get_term($id);
            if (!$term instanceof \WP_Term) {
                continue;
            }
            $translation = $code === null ? null : ($this->languages->termTranslations($term->term_id, $term->taxonomy)[$code] ?? null);
            $shown = $translation === null || $translation === $term->term_id ? null : get_term($translation, $term->taxonomy);
            $names[] = ($shown instanceof \WP_Term ? $shown : $term)->name;
        }

        return implode(', ', $names);
    }
}
