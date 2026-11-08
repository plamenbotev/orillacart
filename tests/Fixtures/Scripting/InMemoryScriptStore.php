<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Scripting;

use OrillaCart\Scripting\Application\ScriptStore;

/** Defines the classes in memory; remembers what it was given. */
final class InMemoryScriptStore implements ScriptStore
{
    /** @var array<string, string> key → source */
    public array $kept = [];

    public function site(): string
    {
        return '1';
    }

    public function load(string $key, string $className): bool
    {
        if (!isset($this->kept[$key])) {
            return false;
        }
        if (!class_exists($className, false)) {
            eval(substr($this->kept[$key], 5));
        }

        return true;
    }

    public function put(string $key, string $className, string $source): void
    {
        $this->kept[$key] = $source;
        if (!class_exists($className, false)) {
            eval(substr($source, 5));
        }
    }

    public function forget(string $keyPrefix): void
    {
        foreach (array_keys($this->kept) as $key) {
            if (str_starts_with($key, $keyPrefix)) {
                unset($this->kept[$key]);
            }
        }
    }
}
