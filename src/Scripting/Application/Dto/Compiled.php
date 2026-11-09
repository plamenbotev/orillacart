<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application\Dto;

/**
 * What compiling a package gave: the PHP source of its class and the class's name, or the findings that stopped
 * it. The manifest is the declarations as data, read from the class once it is loaded.
 */
final readonly class Compiled
{
    /**
     * @param list<Finding>                                                         $findings
     * @param list<array{text: string, plural: ?string, context: string, line: int}> $words    what `__`, `_x`, `_n` and `_nx` mark for translation, by the line of the call
     */
    public function __construct(
        public string $className,
        public ?string $source,
        public array $findings,
        public array $words = [],
    ) {}

    public function ok(): bool
    {
        return $this->source !== null && $this->findings === [];
    }
}
