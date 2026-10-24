<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain;

/**
 * A rule refused some answers, each next to its field, as input validation reports them: fields.vat_number.
 */
final class InvalidFields extends RuleViolation
{
    /**
     * @param non-empty-list<array{field: string, message: string|Text}> $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Some fields are not valid.', 'orillacart_invalid_input');
    }

    /**
     * The refusals in English, as logs and tests read them.
     *
     * @return list<array{field: string, message: string}>
     */
    public function messages(): array
    {
        return array_map(static fn(array $violation): array => ['field' => $violation['field'], 'message' => (string) $violation['message']], $this->violations);
    }
}
