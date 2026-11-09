<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

/**
 * One field an extension declared on the shop's products, orders or customers, as the admin builds its input
 * from: `lead_time: number { label: "Lead time", help: "Days to make it", required: true, filter: true }`.
 */
final readonly class FieldSpec
{
    /**
     * @param list<string> $of       the choices of a `choice`
     * @param bool         $readonly shown in the admin, written by the scripts only
     * @param bool         $filter   a product field shoppers filter the catalogue by: its values go into the search index
     * @param bool         $column   a column of the export, and of the import for a product
     * @param string|null  $written  the label as the declaration writes it, where $label is translated (design 57)
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $label,
        public ?string $help,
        public bool $required,
        public mixed $default,
        public array $of,
        public bool $readonly,
        public bool $filter,
        public bool $column,
        public ?string $written = null,
    ) {}

    /** The label as declared, in every language: what names the field's facet in the search index. */
    public function declaredLabel(): string
    {
        return $this->written ?? $this->label;
    }
}
