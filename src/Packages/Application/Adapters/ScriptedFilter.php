<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Catalog\FindsProducts;
use OrillaCart\Extension\Catalog\ProductFilter;
use OrillaCart\Extension\Order\FindsOrders;
use OrillaCart\Extension\Order\OrderFilter;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Scripting\Domain\Ops;

/**
 * `order_filter "id" { label; fn find(text) }` and `product_filter "id" { label; fn find(text) }`: a filter of the
 * list, which the script marks with shop.orders.mark() / shop.products.mark(); with `find`, a search of the list
 * by the script's own text, answering numbers or ids.
 */
final class ScriptedFilter extends ScriptedProvider implements OrderFilter, FindsOrders, ProductFilter, FindsProducts
{
    public function label(): string
    {
        $label = $this->declaration['props']['label'] ?? null;

        return \is_string($label) ? $this->loaded->word($label) : $this->title();
    }

    public function findOrders(string $text): array
    {
        return $this->find($text);
    }

    public function findProducts(string $text): array
    {
        return $this->find($text);
    }

    public function searches(): bool
    {
        return $this->has('find');
    }

    /** @return list<int> */
    private function find(string $text): array
    {
        try {
            $answer = $this->call('find', [$text]);
        } catch (HandlerFailed) {
            return [];
        }

        return array_values(array_map(static fn(mixed $id): int => (int) Values::text($id), Ops::iterable($answer === null ? [] : $answer)));
    }
}
