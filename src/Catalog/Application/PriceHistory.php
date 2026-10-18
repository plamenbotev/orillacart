<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\OfferedVariation;
use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Domain\PriceHistoryRepository;
use OrillaCart\Catalog\Domain\PricePoint;
use OrillaCart\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * Products' prices for everyone over time, and the lowest of the 30 days before a price began: a sale shows it
 * next to its price, as the EU's Omnibus directive asks of a price reduction. A price is kept when it differs
 * from the one before, as the search index sees products change and sales start or end.
 */
final readonly class PriceHistory
{
    /** How far back the lowest price looks. */
    public const int DAYS = 30;

    public function __construct(
        private PriceHistoryRepository $points,
        private ClockInterface $clock,
    ) {}

    /**
     * Keeps the prices of the offers (a guest's, as the shop shows them to everyone) that changed.
     *
     * @param list<ProductOffer> $offers
     */
    public function record(array $offers): void
    {
        $prices = [];
        foreach ($offers as $offer) {
            $prices["product:{$offer->id}"] = Money::of($offer->price);
            foreach ($offer->variations as $variation) {
                $prices["variation:{$variation->id}"] = Money::of($variation->price);
            }
        }
        if ($prices === []) {
            return;
        }
        $latest = $this->points->latest(array_keys($prices));
        $now = $this->clock->now();
        foreach ($prices as $item => $price) {
            if (!isset($latest[$item]) || !$latest[$item]->price()->equals($price)) {
                $this->points->add(new PricePoint($item, $price, $now));
            }
        }
    }

    /**
     * For each item, the lowest price in effect during the 30 days before its current price began; none for an
     * item whose history does not reach before its current price.
     *
     * @param list<string> $items "product:12", "variation:5"
     *
     * @return array<string, string> by item
     */
    public function lowestBefore(array $items): array
    {
        if ($items === []) {
            return [];
        }
        // A year back covers the 30 days before any current price that began within it.
        $byItem = [];
        foreach ($this->points->since($items, $this->clock->now()->sub(new \DateInterval('P1Y'))) as $point) {
            $byItem[$point->item()][] = $point;
        }
        $lowest = [];
        foreach ($byItem as $item => $points) {
            $current = array_pop($points);
            if ($current === null || $points === []) {
                continue;
            }
            $from = $current->recordedAt()->sub(new \DateInterval('P' . self::DAYS . 'D'));
            $min = null;
            foreach ($points as $index => $point) {
                $next = $points[$index + 1] ?? $current;
                // In effect at some moment of the 30 days: it ended after they began.
                if ($next->recordedAt() > $from && ($min === null || $point->price()->compare($min) < 0)) {
                    $min = $point->price();
                }
            }
            if ($min !== null) {
                $lowest[$item] = (string) $min;
            }
        }

        return $lowest;
    }

    /**
     * @return list<string> the items of the offer, the product and its variations
     */
    public static function items(ProductOffer $offer): array
    {
        return ["product:{$offer->id}", ...array_map(static fn(OfferedVariation $variation): string => "variation:{$variation->id}", $offer->variations)];
    }
}
