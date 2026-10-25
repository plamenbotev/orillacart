<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

use OrillaCart\Shared\Domain\Text;

/**
 * Something the shop is missing: one that stops it from selling, or advice.
 */
final readonly class Readiness
{
    public function __construct(
        public string $key,
        /** True when the shop cannot sell as it should until it is put right. */
        public bool $stops,
        public Text $message,
        /** The admin screen that puts it right, by its page slug: "orillacart-payments". */
        public ?string $fix = null,
    ) {}

    public static function stop(string $key, Text $message, ?string $fix = null): self
    {
        return new self($key, true, $message, $fix);
    }

    public static function advice(string $key, Text $message, ?string $fix = null): self
    {
        return new self($key, false, $message, $fix);
    }
}
