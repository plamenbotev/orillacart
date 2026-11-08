<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application;

use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\HostNamespace;

/**
 * Every function a script may call, by dotted name, with `*` segments matched to what the script wrote
 * (`objects.ticket.query` resolves to `objects.*.query` with "ticket" captured).
 */
final class HostCatalog
{
    /** @var array<string, HostFunction>|null */
    private ?array $functions = null;

    /**
     * @param iterable<HostNamespace> $namespaces
     */
    public function __construct(private readonly iterable $namespaces) {}

    /**
     * @param list<HostFunction> $functions
     */
    public static function of(array $functions): self
    {
        $catalog = new self([]);
        $catalog->functions = [];
        foreach ($functions as $function) {
            $catalog->functions[$function->name] = $function;
        }

        return $catalog;
    }

    /**
     * @return array{HostFunction, list<string>}|null the function and the segments its `*`s matched
     */
    public function resolve(string $name): ?array
    {
        $all = $this->all();
        if (isset($all[$name])) {
            return [$all[$name], []];
        }
        $parts = explode('.', $name);
        foreach ($all as $pattern => $function) {
            if (!str_contains($pattern, '*')) {
                continue;
            }
            $patternParts = explode('.', $pattern);
            if (\count($patternParts) !== \count($parts)) {
                continue;
            }
            $captured = [];
            foreach ($patternParts as $index => $part) {
                if ($part === '*') {
                    $captured[] = $parts[$index];
                } elseif ($part !== $parts[$index]) {
                    continue 2;
                }
            }

            return [$function, $captured];
        }

        return null;
    }

    /**
     * The catalogue with these functions in place of the ones of the same names: a test's fakes.
     *
     * @param list<HostFunction> $functions
     */
    public function with(array $functions): self
    {
        $all = $this->all();
        foreach ($functions as $function) {
            $all[$function->name] = $function;
        }

        return self::of(array_values($all));
    }

    /** Whether a dotted prefix names a namespace or a function: `http`, `shop.orders`. */
    public function knows(string $prefix): bool
    {
        foreach (array_keys($this->all()) as $name) {
            if ($name === $prefix || str_starts_with($name, $prefix . '.')) {
                return true;
            }
            if (str_contains($name, '*') && preg_match('/^' . str_replace(['\*', '\.'], ['[^.]+', '\.'], preg_quote($name, '/')) . '/', $prefix . '.') === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, HostFunction> by name
     */
    public function all(): array
    {
        if ($this->functions === null) {
            $this->functions = [];
            foreach ($this->namespaces as $namespace) {
                foreach ($namespace->functions() as $function) {
                    $this->functions[$function->name] = $function;
                }
            }
            ksort($this->functions);
        }

        return $this->functions;
    }
}
