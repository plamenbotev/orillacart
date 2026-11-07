<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application;

/** The variables a function has declared so far, in source order, and whether it is inside a loop. */
final class Scope
{
    /** @var array<string, true> */
    private array $names = [];
    private int $loops = 0;

    /**
     * @param list<string> $names
     */
    public function __construct(array $names)
    {
        foreach ($names as $name) {
            $this->names[$name] = true;
        }
    }

    public function declare(string $name): void
    {
        $this->names[$name] = true;
    }

    public function has(string $name): bool
    {
        return isset($this->names[$name]);
    }

    /** @return list<string> */
    public function declared(): array
    {
        return array_keys($this->names);
    }

    public function enterLoop(): void
    {
        ++$this->loops;
    }

    public function leaveLoop(): void
    {
        --$this->loops;
    }

    public function inLoop(): bool
    {
        return $this->loops > 0;
    }
}
