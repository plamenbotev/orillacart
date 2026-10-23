<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\AttributeView;
use OrillaCart\Catalog\Application\Dto\OfferedVariation;
use OrillaCart\Catalog\Application\Dto\OptionView;
use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Application\Dto\QuotedBundleItem;
use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Domain\AttributeOption;
use OrillaCart\Catalog\Domain\Backorders;
use OrillaCart\Catalog\Domain\Bundle;
use OrillaCart\Catalog\Domain\DownloadFile;
use OrillaCart\Catalog\Domain\InvalidSelection;
use OrillaCart\Catalog\Domain\PricedItem;
use OrillaCart\Catalog\Domain\Pricing;
use OrillaCart\Catalog\Domain\Product;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Catalog\Domain\StockMode;
use OrillaCart\Catalog\Domain\Variation;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use Psr\Clock\ClockInterface;

/**
 * The catalog as the storefront shows it: today's prices, sales, stock and the choices each product offers.
 */
final readonly class ShopCatalog
{
    public function __construct(
        private ProductRepository $products,
        private ProductContent $content,
        private StockService $stock,
        private ClockInterface $clock,
        private SettingsService $settings,
        private PriceResolver $prices,
        private ProductQuotes $quotes,
        private PriceHistory $history,
    ) {}

    /**
     * @param int|null $customerId who looks, for the prices of their customer groups
     *
     * @throws ProductNotFound also for a product post whose commerce details were never saved
     */
    public function offer(int $id, ?int $customerId = null): ProductOffer
    {
        $product = $this->products->find($id) ?? throw new ProductNotFound($id);

        return $this->toOffer($product, $this->clock->now(), $this->prices->forShopper($customerId), null, $customerId, $this->lowestPrices([$product]));
    }

    /**
     * A bundle with the customer's choices: its price, whether it can be bought, and how many.
     *
     * @param list<int> $choices the variations chosen and the optional products added
     *
     * @throws ProductNotFound for a product that is not a published bundle
     * @throws InvalidSelection
     */
    public function bundleOffer(int $id, array $choices, ?int $customerId = null): ProductOffer
    {
        $product = $this->products->find($id);
        if ($product === null || !$product->isBundle() || !$this->content->isPublished($id)) {
            throw new ProductNotFound($id);
        }

        return $this->toBundleOffer($product, $customerId, $choices);
    }

    /**
     * The offers of the products that have commerce details, keyed by id, in the order asked for.
     *
     * @param list<int> $ids
     * @param int|null  $customerId who looks, for the prices of their customer groups
     *
     * @return array<int, ProductOffer>
     */
    public function offers(array $ids, ?int $customerId = null): array
    {
        $now = $this->clock->now();
        $prices = $this->prices->forShopper($customerId);
        $found = [];
        foreach ($this->products->findMany($ids) as $product) {
            $found[$product->id()] = $product;
        }
        // The stock of every product and variation that counts it, in one query rather than one each.
        $refs = [];
        foreach ($found as $product) {
            if ($product->details()->stockMode === StockMode::Tracked) {
                $refs[] = StockItemRef::product($product->id());
            }
            foreach ($product->variations() as $variation) {
                if ($variation->stockMode() === StockMode::Tracked) {
                    $refs[] = StockItemRef::variation($variation->id());
                }
            }
        }
        $stock = $refs === [] ? [] : $this->stock->availableEach($refs, owedBelowZero: true);
        $lowest = $this->lowestPrices(array_values($found));
        $offers = [];
        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $offers[$id] = $this->toOffer($found[$id], $now, $prices, $stock, $customerId, $lowest);
            }
        }

        return $offers;
    }

    /**
     * When today's price of the product or one of its variations next changes, as a sale starts or ends; null
     * when no change is scheduled or the product has no commerce details.
     */
    public function nextPriceChange(int $id): ?\DateTimeImmutable
    {
        $product = $this->products->find($id);
        if ($product === null) {
            return null;
        }
        $now = $this->clock->now();
        $terms = $this->content->termIds($id);
        // The guest's price changes when a rule for everyone starts or ends, too.
        $next = $this->prices->forShopper(null)->nextChange(new PricedItem($id, false, $terms['categories'], $terms['brands']))
            ?? $this->prices->forShopper(null)->nextChange(new PricedItem($id, true, $terms['categories'], $terms['brands']));
        $pricings = [$product->details()->pricing, ...array_map(static fn(Variation $variation): Pricing => $variation->pricing(), $product->variations())];
        foreach ($pricings as $pricing) {
            $sale = $pricing->sale();
            foreach ([$sale?->from, $sale?->until] as $moment) {
                if ($moment !== null && $moment > $now && ($next === null || $moment < $next)) {
                    $next = $moment;
                }
            }
        }

        return $next;
    }

    /**
     * The lowest prices of the 30 days before today's, when the shop shows them on sales.
     *
     * @param list<Product> $products
     *
     * @return array<string, string> by item: "product:12", "variation:5"
     */
    private function lowestPrices(array $products): array
    {
        if (!$this->settings->current()->showLowestPrice || $products === []) {
            return [];
        }
        $items = [];
        foreach ($products as $product) {
            $items[] = "product:{$product->id()}";
            foreach ($product->variations() as $variation) {
                $items[] = "variation:{$variation->id()}";
            }
        }

        return $this->history->lowestBefore($items);
    }

    /**
     * @param array<string, ?int>|null $stock  how many of each item can be sold, when read for many offers at once
     * @param array<string, string>    $lowest the lowest prices of the 30 days before, by item
     */
    private function toOffer(Product $product, \DateTimeImmutable $now, ShopperPricing $prices, ?array $stock = null, ?int $customerId = null, array $lowest = []): ProductOffer
    {
        $details = $product->details();
        if ($product->isBundle()) {
            return $this->toBundleOffer($product, $customerId);
        }
        $terms = $this->content->termIds($product->id());
        $item = static fn(Pricing $pricing): PricedItem => new PricedItem($product->id(), $pricing->priceAt($now)->compare($pricing->price()) < 0, $terms['categories'], $terms['brands']);
        $priceHidden = $details->priceVisibility->isHidden($this->settings->current()->hidePrices);
        $sellable = $this->content->isPublished($product->id()) && $details->purchasable && !$priceHidden;

        $attributes = array_map(static fn(array $offered): AttributeView => new AttributeView(
            $offered[0]->id(),
            $offered[0]->name(),
            $offered[0]->isRequired(),
            $offered[0]->isPriceHidden(),
            array_map(OptionView::fromOption(...), $offered[1]),
            $offered[0]->set()?->id(),
        ), $product->offeredOptions());
        $downloads = array_map(static fn(DownloadFile $file): string => $file->name(), $product->downloads());

        if ($product->hasVariations()) {
            $variations = array_map(fn(Variation $variation): OfferedVariation => $this->toVariation($variation, $details->backorders, $sellable, $now, $prices, $item($variation->pricing()), $stock, $lowest, $details->backorderLimit), $product->variations());
            $prices = array_map(static fn(OfferedVariation $variation): Money => Money::of($variation->price), $variations);
            usort($prices, static fn(Money $a, Money $b): int => $a->compare($b));
            $lowest = $prices[0] ?? $product->priceAt($now);
            $highest = $prices === [] ? $lowest : $prices[\count($prices) - 1];

            return new ProductOffer(
                $product->id(),
                $this->content->name($product->id()),
                $details->type->value,
                $details->sku,
                (string) $lowest,
                null,
                $highest->compare($lowest) === 0 ? null : (string) $highest,
                array_filter($variations, static fn(OfferedVariation $variation): bool => $variation->forSale) !== [],
                null,
                $details->featured,
                $attributes,
                $variations,
                $downloads,
                $priceHidden,
                taxParts: [['taxable' => $details->taxable, 'group' => $details->taxGroupId, 'weight' => '1']],
            );
        }

        [$available, $onBackorder] = $this->available($details->stockMode, StockItemRef::product($product->id()), $details->backorders, $stock, $details->backorderLimit);
        $own = $details->pricing->priceAt($now);
        $price = $prices->price($own, $item($details->pricing), regular: $details->pricing->price());
        $regular = self::regularPrice($details->pricing, $price);

        return new ProductOffer(
            $product->id(),
            $this->content->name($product->id()),
            $details->type->value,
            $details->sku,
            (string) $price,
            $regular,
            null,
            $sellable && $available !== 0,
            $available,
            $details->featured,
            $attributes,
            [],
            $downloads,
            $priceHidden,
            $onBackorder,
            $prices->tiers($own, $item($details->pricing), regular: $details->pricing->price()),
            lowestPrice: $regular === null ? null : ($lowest["product:{$product->id()}"] ?? null),
            soldOut: $sellable && $available === 0,
            taxParts: [['taxable' => $details->taxable, 'group' => $details->taxGroupId, 'weight' => '1']],
        );
    }

    /**
     * A bundle as the cart prices it: from its items, available while each is. Without choices, as the page first
     * shows it: the first variation of each choice, without optional items.
     *
     * @param list<int>|null $choices
     *
     * @throws InvalidSelection only for choices given
     */
    private function toBundleOffer(Product $product, ?int $customerId, ?array $choices = null): ProductOffer
    {
        $details = $product->details();
        $bundle = $product->bundle();
        \assert($bundle !== null);
        try {
            $quote = $this->quotes->quote(new QuoteRequest($product->id(), [], 1, $customerId, $choices));
        } catch (NotFound|RuleViolation $e) {
            if ($choices !== null && $e instanceof InvalidSelection) {
                throw $e;
            }
            $quote = null;
        }
        $priceHidden = $details->priceVisibility->isHidden($this->settings->current()->hidePrices);

        return new ProductOffer(
            $product->id(),
            $this->content->name($product->id()),
            $details->type->value,
            $details->sku,
            $quote->unitPrice ?? '0',
            null,
            null,
            $quote !== null && $quote->forSale,
            $quote?->available,
            $details->featured,
            [],
            [],
            array_map(static fn(DownloadFile $file): string => $file->name(), $product->downloads()),
            $priceHidden,
            false,
            [],
            array_map(static fn(QuotedBundleItem $item): array => ['name' => $item->name, 'quantity' => $item->quantity], $quote->contents ?? []),
            $choices === null ? $this->bundleChoices($bundle, $customerId) : [],
            taxParts: $quote === null || $quote->contents === []
                ? [['taxable' => $details->taxable, 'group' => $details->taxGroupId, 'weight' => '1']]
                : array_map(static fn(QuotedBundleItem $item): array => ['taxable' => $item->taxable, 'group' => $item->taxGroupId, 'weight' => $item->price], $quote->contents),
        );
    }

    /**
     * What the customer decides about a bundle: which variation of an item, and whether to add an optional one,
     * with what an optional item adds to the price.
     *
     * @return list<array{product_id: int, name: string, quantity: int, optional: bool, choices: list<array{id: int, name: string, for_sale: bool, price: ?string}>}>
     */
    private function bundleChoices(Bundle $bundle, ?int $customerId): array
    {
        $decisions = [];
        foreach ($bundle->items as $item) {
            if (!$item->asksTheCustomer()) {
                continue;
            }
            $choices = [];
            foreach ($item->pickIds() as $pick) {
                $variationId = $item->variationIds === [] ? null : $pick;
                try {
                    $quote = $this->quotes->quote(new QuoteRequest($item->productId, $this->signature($item->productId, $variationId), $item->quantity, $customerId));
                } catch (NotFound|RuleViolation) {
                    continue;
                }
                $price = Money::of($quote->unitPrice)->times($item->quantity);
                $choices[] = [
                    'id' => $pick,
                    'name' => $variationId === null ? $quote->name : $this->variationName($item->productId, $variationId),
                    'for_sale' => $quote->forSale && ($quote->available === null || $quote->available >= $item->quantity),
                    'price' => $item->optional ? (string) ($bundle->pricing === Bundle::SUM ? $bundle->priceOf($price) : $price) : null,
                ];
            }
            $decisions[] = [
                'product_id' => $item->productId,
                'name' => $this->content->name($item->productId),
                'quantity' => $item->quantity,
                'optional' => $item->optional,
                'choices' => $choices,
            ];
        }

        return $decisions;
    }

    /**
     * @return list<int>
     */
    private function signature(int $productId, ?int $variationId): array
    {
        foreach ($variationId === null ? [] : $this->products->find($productId)?->variations() ?? [] as $variation) {
            if ($variation->id() === $variationId) {
                return $variation->signature();
            }
        }

        return [];
    }

    private function variationName(int $productId, int $variationId): string
    {
        foreach ($this->products->find($productId)?->variations() ?? [] as $variation) {
            if ($variation->id() === $variationId) {
                return implode(' / ', array_map(static fn(AttributeOption $option): string => $option->name(), $variation->options()));
            }
        }

        return '#' . $variationId;
    }

    /**
     * @param array<string, ?int>|null $stock
     * @param array<string, string>    $lowest
     */
    private function toVariation(Variation $variation, Backorders $productBackorders, bool $sellable, \DateTimeImmutable $now, ShopperPricing $prices, PricedItem $item, ?array $stock = null, array $lowest = [], ?int $productBackorderLimit = null): OfferedVariation
    {
        $price = $prices->price($variation->pricing()->priceAt($now), $item, variationId: $variation->id(), regular: $variation->pricing()->price());
        [$available, $onBackorder] = $this->available($variation->stockMode(), StockItemRef::variation($variation->id()), $variation->backorders() ?? $productBackorders, $stock, $variation->backorderLimit() ?? $productBackorderLimit);

        return new OfferedVariation(
            $variation->id(),
            $variation->signature(),
            implode(' / ', array_map(static fn(AttributeOption $option): string => $option->name(), $variation->options())),
            $variation->sku(),
            (string) $price,
            $regular = self::regularPrice($variation->pricing(), $price),
            $sellable && $available !== 0,
            $available,
            $onBackorder,
            $regular === null ? null : ($lowest["variation:{$variation->id()}"] ?? null),
            $sellable && $available === 0,
        );
    }

    /**
     * How many can be sold, null when stock does not limit it (as with backorders without a limit); and whether it is out of
     * stock on a backorder the customer is told of.
     *
     * @param array<string, ?int>|null $stock read for many offers at once; otherwise the item's is read now
     *
     * @return array{?int, bool}
     */
    private function available(StockMode $mode, StockItemRef $ref, Backorders $backorders, ?array $stock = null, ?int $backorderLimit = null): array
    {
        if ($mode !== StockMode::Tracked) {
            return [$mode === StockMode::Unavailable ? 0 : null, false];
        }
        $key = "{$ref->kind}:{$ref->id}";
        // Below zero by what is owed already.
        $level = $stock !== null && \array_key_exists($key, $stock) ? $stock[$key] : $this->stock->available([$ref], owedBelowZero: true);
        if ($level === null || !$backorders->allowed()) {
            return [$level === null ? null : max(0, $level), false];
        }

        return [$backorderLimit === null ? null : max(0, $level + $backorderLimit), $backorders === Backorders::Notify && $level <= 0];
    }

    /** The regular price, shown struck through, when a sale or a price rule makes it less. */
    private static function regularPrice(Pricing $pricing, Money $price): ?string
    {
        return $price->compare($pricing->price()) < 0 ? (string) $pricing->price() : null;
    }
}
