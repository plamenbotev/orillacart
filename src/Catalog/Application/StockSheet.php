<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\StockRowView;
use OrillaCart\Catalog\Application\Dto\StockSheetQuery;
use OrillaCart\Catalog\Application\Dto\StockSheetView;
use OrillaCart\Catalog\Domain\AttributeOption;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Catalog\Domain\ProductSearch;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\Dto\StockroomView;
use OrillaCart\Inventory\Application\StockroomService;
use OrillaCart\Inventory\Application\StockService;

/**
 * The stock of the whole catalog on one sheet, for the shop to count and correct: products (each variation
 * on its own line, in place of its product) or attribute options, by stockroom. Changes go to
 * StockService::changeLevels().
 */
final readonly class StockSheet
{
    public function __construct(
        private ProductRepository $products,
        private ProductSearch $search,
        private ProductContent $content,
        private StockService $stock,
        private StockroomService $stockrooms,
    ) {}

    public function sheet(StockSheetQuery $query): StockSheetView
    {
        $stockrooms = array_values(array_filter(
            $this->stockrooms->stockrooms(),
            static fn(StockroomView $stockroom): bool => $query->stockroom === null || $stockroom->id === $query->stockroom,
        ));
        $offset = ($query->page - 1) * $query->perPage;
        $text = $query->search === null || trim($query->search) === '' ? null : trim($query->search);

        [$rows, $total] = $query->kind === 'options'
            ? $this->options($text, $offset, $query->perPage)
            : $this->items($text, $query->category, $query->parent, $offset, $query->perPage);

        $levels = $this->stock->levelsOf(array_map(static fn(StockRowView $row): StockItemRef => new StockItemRef($row->kind, $row->id), $rows));
        $shown = array_map(static fn(StockroomView $stockroom): int => $stockroom->id, $stockrooms);
        $rows = array_map(static fn(StockRowView $row): StockRowView => new StockRowView(
            $row->kind,
            $row->id,
            $row->productId,
            $row->name,
            $row->owner,
            $row->sku,
            $row->stockMode,
            array_intersect_key($levels["{$row->kind}:{$row->id}"] ?? [], array_flip($shown)),
        ), $rows);

        return new StockSheetView($stockrooms, $rows, $total, $query->page, max(1, (int) ceil($total / $query->perPage)));
    }

    /**
     * @return array{list<StockRowView>, int}
     */
    private function items(?string $text, ?int $category, ?int $parent, int $offset, int $limit): array
    {
        if ($parent !== null) {
            [$ids, $total] = [[$parent], 1];
        } else {
            [$ids, $total] = $this->search->find($text, $category, $text === null ? [] : $this->matchingIds($text), $offset, $limit);
        }

        $found = [];
        foreach ($this->products->findMany($ids) as $product) {
            $found[$product->id()] = $product;
        }
        $rows = [];
        foreach ($ids as $id) {
            $product = $found[$id] ?? null;
            if ($product === null) {
                continue;
            }
            $name = $this->content->name($id);
            $details = $product->details();
            if (!$product->hasVariations()) {
                $rows[] = new StockRowView('product', $id, $id, $name, '', $details->sku, $details->stockMode->value, []);
                continue;
            }
            foreach ($product->variations() as $variation) {
                $label = implode(' / ', array_map(static fn(AttributeOption $option): string => $option->name(), $variation->options()));
                $rows[] = new StockRowView('variation', $variation->id(), $id, $name . ' – ' . $label, '', $variation->sku(), $variation->stockMode()->value, []);
            }
        }

        return [$rows, $total];
    }

    /**
     * Products the text names other than by their name: by SKU (theirs or a variation's), or by id (theirs or a
     * variation's).
     *
     * @return list<int>
     */
    private function matchingIds(string $text): array
    {
        $ids = $this->products->idsWithSku($text);
        if (ctype_digit($text) && (int) $text > 0) {
            $id = (int) $text;
            $ids[] = $this->products->findByVariation($id)?->id() ?? $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array{list<StockRowView>, int}
     */
    private function options(?string $text, int $offset, int $limit): array
    {
        [$options, $total] = $this->products->options($text, $offset, $limit);
        $rows = array_map(function (AttributeOption $option): StockRowView {
            $attribute = $option->attribute();
            $product = $attribute->product();

            return new StockRowView(
                'option',
                $option->id(),
                $product?->id(),
                $attribute->name() . ': ' . $option->name(),
                $attribute->set()?->name() ?? ($product === null ? '' : $this->content->name($product->id())),
                null,
                'tracked',
                [],
            );
        }, $options);

        return [$rows, $total];
    }
}
