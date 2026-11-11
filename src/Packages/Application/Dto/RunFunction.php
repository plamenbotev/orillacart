<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** The console: one top-level function of the extension, with its arguments. */
final readonly class RunFunction
{
    /**
     * @param list<mixed> $arguments
     */
    public function __construct(
        #[Assert\Regex(pattern: '/^[A-Za-z_][A-Za-z0-9_]*$/')]
        public string $function,
        public array $arguments = [],
        /** Records the run for its debugger (design 71): the answer has its recording. */
        public bool $debug = false,
    ) {}
}
