<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application\Dto;

/**
 * What a tool answered: the data, or why it refused, for the model to read and correct.
 */
final readonly class ToolOutcome
{
    /**
     * @param mixed       $data    the answer, as JSON would carry it
     * @param string|null $refusal the reason, when the tool refused
     */
    private function __construct(
        public mixed $data,
        public ?string $refusal,
    ) {}

    public static function done(mixed $data): self
    {
        return new self($data, null);
    }

    /**
     * @param mixed $details what the model needs to correct the call, such as each field's violation
     */
    public static function refused(string $reason, mixed $details = null): self
    {
        return new self($details, $reason);
    }

    public function isError(): bool
    {
        return $this->refusal !== null;
    }
}
