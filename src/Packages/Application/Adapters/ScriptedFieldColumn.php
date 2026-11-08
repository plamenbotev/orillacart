<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Catalog\ImportsProductColumn;
use OrillaCart\Extension\Catalog\ProductItem;
use OrillaCart\Extension\Order\OrderColumn;
use OrillaCart\Extension\Order\OrderSummary;
use OrillaCart\Packages\Application\Dto\FieldSection;
use OrillaCart\Packages\Application\Dto\FieldSpec;
use OrillaCart\Packages\Application\ExtensionFields;
use OrillaCart\Packages\Application\ObjectStore;

/**
 * A field an extension declares on products or orders as a column of the export, `x_<extension>_<field>`, named
 * by the field's label: `fields on product { lead_time: number { label: "Lead time" } }` is the column "Lead
 * time" of the products file. A product's column imports too: a cell changes the field, an empty one clears
 * it, and a cell the field does not take is reported with the row. A field with `column: false` has none.
 */
final readonly class ScriptedFieldColumn implements ImportsProductColumn, OrderColumn
{
    private const int LONGEST_ID = 40;

    public function __construct(
        private string $owner,
        private FieldSection $section,
        private FieldSpec $field,
        private ObjectStore $store,
    ) {}

    public function id(): string
    {
        return substr('x_' . $this->section->extension . '_' . $this->field->name, 0, self::LONGEST_ID);
    }

    public function label(): string
    {
        return $this->field->label;
    }

    public function value(OrderSummary|ProductItem $row): string
    {
        if ($row instanceof ProductItem) {
            // The field is the product's: its variations' rows leave the cell empty, as the shop's own product columns do.
            return $row->variationId !== null ? '' : ExtensionFields::text($this->field, $this->store->fieldValue($this->section->extension, $this->owner, $row->productId, $this->field->name));
        }

        return ExtensionFields::text($this->field, $this->store->fieldValue($this->section->extension, $this->owner, $row->number, $this->field->name));
    }

    public function import(ProductItem $item, string $value): void
    {
        if ($item->variationId !== null) {
            return;
        }
        $this->store->setField($this->section->extension, $this->owner, $item->productId, $this->field->name, ExtensionFields::parse($this->section, $this->field, $value));
    }
}
