<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application\Dto;

use OrillaCart\Forms\Domain\Form;
use OrillaCart\Forms\Domain\FormField;

final readonly class FormView
{
    /**
     * @param list<int>                  $categoryIds
     * @param list<int>                  $productIds
     * @param list<array<string, mixed>> $fields      as saved: key, label, kind and the rest
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $placement,
        public bool $active,
        public int $position,
        public string $scope,
        public array $categoryIds,
        public array $productIds,
        public array $fields,
    ) {}

    public static function fromForm(Form $form): self
    {
        return new self($form->id(), $form->name(), $form->placement()->value, $form->isActive(), $form->position(), $form->scope()->value, $form->categoryIds(), $form->productIds(), array_map(static fn(FormField $field): array => $field->toRow(), $form->fields()));
    }
}
