<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain;

/**
 * A rule refused some answers, each next to its field, as input validation reports them: fields.vat_number.
 */
final class InvalidFields extends RuleViolation
{
    /**
     * @param non-empty-list<array{field: string, message: string}> $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Some fields are not valid.', 'orillacart_invalid_input');
    }
}
