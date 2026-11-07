<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application;

/**
 * What a compiled package is: a class whose static manifest holds its declarations as data, and whose methods
 * are its functions, each run with a context that holds the budget and the host.
 */
abstract class CompiledScript
{
    public function __construct(protected readonly Context $c) {}

    /**
     * The declarations as data: for each, kind, words, name, props (specs as `['$spec' => name, …options]`,
     * functions as their method names), functions by name, nested declarations, and the handler's method.
     *
     * @return list<array{kind: string, words: list<string>, name: ?string, props: array<string, mixed>, functions: array<string, string>, nested: list<array<string, mixed>>, handler: ?string}>
     */
    abstract public static function manifest(): array;

    /** Calls one of the class's methods with the script's arguments. */
    public function run(string $method, mixed ...$args): mixed
    {
        return new \ReflectionMethod($this, $method)->invoke($this, ...$args);
    }
}
