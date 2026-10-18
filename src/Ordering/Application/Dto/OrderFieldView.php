<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\FieldAnswer;

/**
 * An answer to an add-on's checkout field, with the field's label as it was asked.
 */
final readonly class OrderFieldView
{
    public function __construct(
        public string $id,
        public string $label,
        public string $answer,
    ) {}

    public static function fromAnswer(FieldAnswer $field): self
    {
        return new self($field->id, $field->label, $field->answer);
    }

    public function toAnswer(): FieldAnswer
    {
        return new FieldAnswer($this->id, $this->label, $this->answer);
    }
}
