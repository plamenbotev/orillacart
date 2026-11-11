<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Command: debug one of an extension's tests, or open one of its recordings (design 71). */
final readonly class DebugExtension
{
    /**
     * @param array<string, string>|null $files the files to test instead of the saved ones, path → content
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
