<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Domain;

/**
 * A field is asked only when an earlier field of its form is answered (or ticked), or, with values, has one of them.
 */
final readonly class FieldCondition
{
    /**
     * @param string       $field  the other field's key
     * @param list<string> $values that field's choice values; none for any answer
     */
    public function __construct(
        public string $field,
        public array $values = [],
    ) {}
}
