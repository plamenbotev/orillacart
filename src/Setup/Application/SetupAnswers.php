<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application;

/**
 * The owner's answers so far, each step's as its DTO. A step reads its own and those of the steps before it (tax
 * reads where the shop sells).
 */
final readonly class SetupAnswers
{
    /** @param array<string, object> $sections by step */
    public function __construct(private array $sections = []) {}

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function of(string $step, string $class): ?object
    {
        $section = $this->sections[$step] ?? null;

        return $section instanceof $class ? $section : null;
    }

    /** @return array<string, object> */
    public function all(): array
    {
        return $this->sections;
    }
}
