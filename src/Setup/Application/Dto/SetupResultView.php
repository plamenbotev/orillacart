<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

use OrillaCart\Shared\Domain\Text;

/**
 * What applying the plan did: the changes made, those kept as they were, and the one that was refused, after
 * which nothing more was made. Applying again makes what is still missing.
 */
final readonly class SetupResultView
{
    /**
     * @param list<string>                         $applied
     * @param list<string>                         $kept
     * @param array{key: string, reason: Text}|null $refused
     */
    public function __construct(
        public array $applied,
        public array $kept,
        public ?array $refused = null,
    ) {}
}
