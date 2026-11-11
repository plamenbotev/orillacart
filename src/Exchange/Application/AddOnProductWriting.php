<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

use OrillaCart\Catalog\Application\AddOnAttributeSets;
use OrillaCart\Catalog\Application\AddOnProducts;
use OrillaCart\Catalog\Application\Dto\AttributeView;
use OrillaCart\Catalog\Application\Dto\OptionView;
use OrillaCart\Catalog\Application\Dto\ProductView;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveVariation;
use OrillaCart\Catalog\Application\ProductLookup;
use OrillaCart\Catalog\Application\ProductService;
use OrillaCart\Exchange\Application\Dto\ProductPostChange;
use OrillaCart\Extension\Catalog\ProductDraft;
use OrillaCart\Extension\Catalog\ProductSummary;
use OrillaCart\Extension\Catalog\ProductVariationSummary;
use OrillaCart\Extension\Catalog\VariationDraft;
use OrillaCart\Extension\Catalog\WritesProducts;
use OrillaCart\Inventory\Application\StockroomService;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Validation\CommandChecks;

/**
 * Products made and changed by add-ons (design 69), as the import makes and changes them: a draft is one row of a
 * products file, so every value is checked as a cell is and a refused one changes nothing; attributes and attribute
 * sets are saved as the product editor saves them. Texts are written as by nobody, so they are cleaned as for an
 * author without unfiltered HTML, whoever's request runs the add-on. A new product is a draft until publish().
 */
final readonly class AddOnProductWriting implements WritesProducts
{
    public function __construct(
        private ProductRows $rows,
        private ProductService $products,
        private ProductPosts $posts,
        private ProductLookup $lookup,
        private StockroomService $stockrooms,
        private AddOnProducts $read,
        private CommandChecks $checks,
    ) {}

    public function create(ProductDraft $draft): ProductSummary
    {
        if (trim($draft->name ?? '') === '' || ($draft->price ?? '') === '') {
            throw new \InvalidArgumentException('A new product needs a name and a price.');
        }
        $this->skuFree($draft->sku, null);
        $row = $this->row($draft) + ['status' => 'draft'];
        $this->attributesCheck($draft);

        return $this->refusing(function () use ($row, $draft): ProductSummary {
            $id = $this->posts->asUser(null, fn(): int => $this->rows->import($row)[2]);
            try {
                $this->details($id, $draft);
            } catch (\Throwable $e) {
                $this->posts->delete($id);

                throw $e;
            }

            return $this->summary($id);
        });
    }

    public function change(int $productId, ProductDraft $changes): ProductSummary
    {
        $this->exists($productId);
        $this->skuFree($changes->sku, $productId);
        $row = $this->row($changes);
        $this->attributesCheck($changes);

        return $this->refusing(function () use ($productId, $row, $changes): ProductSummary {
            $this->details($productId, $changes);
            if ($row !== []) {
                $this->posts->asUser(null, fn(): array => $this->rows->import(['id' => (string) $productId] + $row));
            }

            return $this->summary($productId);
        });
    }

    public function addVariation(int $productId, VariationDraft $draft): ProductVariationSummary
    {
        $product = $this->exists($productId);
        if ($draft->options === []) {
            throw new \InvalidArgumentException('Name the variation\'s option of each attribute it differs in: { "Colour": "Red" }.');
        }
        $this->skuFree($draft->sku, null);
        $command = new SaveVariation(self::optionIds($product, $draft->options), $draft->price ?? $product->price, sku: ($draft->sku ?? '') === '' ? null : $draft->sku);
        $this->checks->check($command);
        $cells = $this->variationCells($draft, false);

        return $this->refusing(function () use ($productId, $command, $cells): ProductVariationSummary {
            $variation = $this->products->addVariation($productId, $command);
            if ($cells !== []) {
                $this->rows->import(['id' => (string) $productId, 'variation_id' => (string) $variation->id] + $cells);
            }

            return $this->variation($productId, $variation->id);
        });
    }

    public function changeVariation(int $productId, int $variationId, VariationDraft $changes): ProductVariationSummary
    {
        $this->exists($productId);
        if ($changes->options !== []) {
            throw new \InvalidArgumentException('A variation\'s options do not change: add a variation with the new ones.');
        }
        $this->skuFree($changes->sku, $productId);
        $cells = $this->variationCells($changes, true);

        return $this->refusing(function () use ($productId, $variationId, $cells): ProductVariationSummary {
            if ($cells !== []) {
                $this->rows->import(['id' => (string) $productId, 'variation_id' => (string) $variationId] + $cells);
            }

            return $this->variation($productId, $variationId);
        });
    }

    public function publish(int $productId): void
    {
        $this->exists($productId);
        $this->posts->asUser(null, fn() => $this->posts->change($productId, new ProductPostChange(status: 'publish')));
    }

    public function unpublish(int $productId): void
    {
        $this->exists($productId);
        $this->posts->asUser(null, fn() => $this->posts->change($productId, new ProductPostChange(status: 'draft')));
    }

    public function trash(int $productId): void
    {
        $this->exists($productId);
        $this->posts->trash($productId);
    }

    /**
     * The draft as a products file's row: only the cells it gives.
     *
     * @return array<string, string>
     */
    private function row(ProductDraft $draft): array
    {
        $row = [];
        foreach (['name' => $draft->name, 'price' => $draft->price, 'type' => $draft->type, 'sku' => $draft->sku, 'sale_price' => $draft->salePrice, 'description' => $draft->description, 'short_description' => $draft->shortDescription, 'stock_mode' => $draft->stockMode, 'weight' => $draft->weight] as $column => $value) {
            if ($value !== null) {
                $row[$column] = $value;
            }
        }
        if ($draft->featured !== null) {
            $row['featured'] = $draft->featured ? 'yes' : 'no';
        }
        foreach (['categories' => $draft->categories, 'tags' => $draft->tags] as $column => $names) {
            if ($names !== null) {
                foreach ($names as $name) {
                    if (str_contains($name, '|')) {
                        throw new \InvalidArgumentException(\sprintf('%s: "%s" has a "|", which no name may have.', $column, $name));
                    }
                }
                $row[$column] = implode(ProductColumns::LIST, $names);
            }
        }

        return $row + $this->stockCells($draft->stock, $draft->stockrooms);
    }

    /** @return array<string, string> */
    private function variationCells(VariationDraft $draft, bool $withPrice): array
    {
        $cells = [];
        foreach (['price' => $withPrice ? $draft->price : null, 'sku' => $withPrice ? $draft->sku : null, 'sale_price' => $draft->salePrice] as $column => $value) {
            if ($value !== null) {
                $cells[$column] = $value;
            }
        }

        return $cells + $this->stockCells($draft->stock, null);
    }

    /**
     * @param array<int, int>|null $rooms
     *
     * @return array<string, string>
     */
    private function stockCells(?int $stock, ?array $rooms): array
    {
        $levels = [];
        if ($stock !== null) {
            $first = $this->stockrooms->stockrooms()[0] ?? throw new \InvalidArgumentException('The shop has no stockroom to keep the stock in.');
            $levels[$first->id] = $stock;
        }
        $known = array_map(static fn($room): int => $room->id, $this->stockrooms->stockrooms());
        foreach ($rooms ?? [] as $room => $level) {
            if (!\in_array($room, $known, true)) {
                throw new \InvalidArgumentException(\sprintf('The shop has no stockroom %d.', $room));
            }
            $levels[$room] = $level;
        }
        $cells = [];
        foreach ($levels as $room => $level) {
            $cells[ProductColumns::STOCK . $room] = (string) $level;
        }

        return $cells;
    }

    /** The product's own attributes and its sets, saved as the product editor saves them. */
    private function details(int $productId, ProductDraft $draft): void
    {
        if ($draft->attributes === null && $draft->attributeSetIds === null) {
            return;
        }
        $view = $this->products->product($productId);
        $changes = [];
        if ($draft->attributes !== null) {
            $changes['attributes'] = AddOnAttributeSets::attributes($draft->attributes, $view->attributes);
        }
        if ($draft->attributeSetIds !== null) {
            $changes['setIds'] = array_values(array_unique($draft->attributeSetIds));
        }
        $command = SaveProduct::unchanged($view, $changes);
        $this->checks->check($command);
        $this->products->save($productId, $command);
    }

    /** The attributes, checked before anything is written. */
    private function attributesCheck(ProductDraft $draft): void
    {
        foreach (AddOnAttributeSets::attributes($draft->attributes ?? [], []) as $attribute) {
            $this->checks->check($attribute);
        }
    }

    private function skuFree(?string $sku, ?int $productId): void
    {
        if ($sku === null || $sku === '') {
            return;
        }
        $owner = $this->lookup->withSku($sku);
        if ($owner !== null && $owner[0] !== $productId) {
            throw new \InvalidArgumentException(\sprintf('Product %d has the SKU "%s" already.', $owner[0], $sku));
        }
    }

    private function exists(int $productId): ProductView
    {
        if ($productId <= 0 || $this->posts->of([$productId]) === []) {
            throw new \InvalidArgumentException(\sprintf('There is no product %d in this shop.', $productId));
        }

        return $this->products->product($productId);
    }

    /**
     * @param array<string, string> $options attribute name → option name
     *
     * @return list<int>
     */
    private static function optionIds(ProductView $product, array $options): array
    {
        $ids = [];
        foreach ($options as $attributeName => $optionName) {
            $attribute = array_find($product->offered, static fn(AttributeView $a): bool => mb_strtolower($a->name) === mb_strtolower(trim((string) $attributeName)));
            if (!$attribute instanceof AttributeView) {
                throw new \InvalidArgumentException(\sprintf('Product %d offers no attribute "%s"; it offers %s.', $product->id, $attributeName, $product->offered === [] ? 'none' : implode(', ', array_map(static fn(AttributeView $a): string => $a->name, $product->offered))));
            }
            $option = array_find($attribute->options, static fn(OptionView $o): bool => mb_strtolower($o->name) === mb_strtolower(trim($optionName)));
            if (!$option instanceof OptionView) {
                throw new \InvalidArgumentException(\sprintf('"%s" has no option "%s"; it has %s.', $attribute->name, $optionName, implode(', ', array_map(static fn(OptionView $o): string => $o->name, $attribute->options))));
            }
            $ids[] = $option->id;
        }

        return $ids;
    }

    private function summary(int $productId): ProductSummary
    {
        return $this->read->find($productId) ?? throw new \InvalidArgumentException(\sprintf('There is no product %d in this shop.', $productId));
    }

    private function variation(int $productId, int $variationId): ProductVariationSummary
    {
        foreach ($this->summary($productId)->variations as $variation) {
            if ($variation->id === $variationId) {
                return $variation;
            }
        }

        throw new \InvalidArgumentException(\sprintf('Product %d has no variation %d.', $productId, $variationId));
    }

    /**
     * What the shop refuses, said to the add-on as a refusal.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function refusing(\Closure $work): mixed
    {
        try {
            return $work();
        } catch (RuleViolation $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }
}
