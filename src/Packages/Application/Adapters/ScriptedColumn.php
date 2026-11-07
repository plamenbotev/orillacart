<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Catalog\ProductColumn;
use OrillaCart\Extension\Catalog\ProductItem;
use OrillaCart\Extension\Order\OrderColumn;
use OrillaCart\Extension\Order\OrderSummary;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;

/** `order_column "id" { label; fn value(order) }` and `product_column "id" { label; fn value(item) }`: a column of the exports. */
final class ScriptedColumn extends ScriptedProvider implements OrderColumn, ProductColumn
{
    public function label(): string
    {
        $label = $this->declaration['props']['label'] ?? null;

        return \is_string($label) ? $label : $this->title();
    }

    public function value(OrderSummary|ProductItem $row): string
    {
        try {
            return Values::text($this->call('value', [$row instanceof OrderSummary ? Values::order($row) : Values::record($row)]));
        } catch (HandlerFailed) {
            return '';
        }
    }
}
