<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Command: run an extension's own tests, over its saved files or with some of them changed. */
final readonly class TestExtension
{
    /**
     * @param array<string, string|null>|null $files path → content laid over the saved files: a path sent stands in
     *                                               for the saved one or is added, null removes one, the others stay
     */
    public function __construct(
        public ?array $files = null,
        /** A test to record for the debugger (design 71), by its name: its result has the recording. */
        #[Assert\Length(min: 1, max: 200)]
        public ?string $debug = null,
    ) {}
}
