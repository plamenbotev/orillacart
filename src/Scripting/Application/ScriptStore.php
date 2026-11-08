<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application;

/**
 * Where compiled classes live: a cache folder per site, build and checksum, or memory when there is none. Loading
 * a class that is there costs a `require`; one that is not is compiled again by the caller and put.
 */
interface ScriptStore
{
    /** The site whose classes the store keeps: its id, part of every class's namespace and key. */
    public function site(): string;

    /** Loads the class if the store has it; whether it is defined now. */
    public function load(string $key, string $className): bool;

    /** Keeps the source under the key and defines the class. */
    public function put(string $key, string $className, string $source): void;

    /** Forgets what was kept under the key, or everything of a prefix. */
    public function forget(string $keyPrefix): void;
}
