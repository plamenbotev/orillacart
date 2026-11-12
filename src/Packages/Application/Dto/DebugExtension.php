<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Command: debug one of an extension's tests, or open one of its recordings (design 71). */
final readonly class DebugExtension
{
    /**
     * @param array<string, string|null>|null $files path → content laid over the saved files: a path sent stands in
     *                                               for the saved one or is added, null removes one, the others stay
     */
    public function __construct(
        /** The test to run with a recording, by its name. */
        #[Assert\Length(min: 1, max: 200)]
        public ?string $test = null,
        /** A recording to open instead, by its id. */
        #[Assert\Positive]
        public ?int $recording = null,
        public ?array $files = null,
    ) {}
}
