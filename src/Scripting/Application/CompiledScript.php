<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application;

use OrillaCart\Scripting\Domain\ScriptError;

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

    /**
     * Calls one of the class's methods with the script's arguments.
     *
     * @throws ScriptError when the arguments are too few or too many for the function: a failure of the script's,
     *                     not of the host's, so a caller sees it as any other
     */
    public function run(string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($this, $method);
        $given = \count($args);
        $least = $reflection->getNumberOfRequiredParameters();
        $most = $reflection->isVariadic() ? \PHP_INT_MAX : $reflection->getNumberOfParameters();
        if ($given < $least || $given > $most) {
            $name = str_starts_with($method, 'fn_') ? substr($method, 3) : 'the function';
            $expected = $least === $most ? \sprintf('%d argument%s', $least, $least === 1 ? '' : 's') : \sprintf('%d to %d arguments', $least, $most);

            throw new ScriptError(\sprintf('%s expects %s, %d given', $name, $expected, $given));
        }

        return $reflection->invoke($this, ...$args);
    }
}
