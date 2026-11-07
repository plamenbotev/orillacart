<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application;

use OrillaCart\Scripting\Application\Dto\Budget;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Domain\BudgetExceeded;
use OrillaCart\Scripting\Domain\ScriptError;

/**
 * One run of a script: its budget, counted down by the generated code at every loop iteration and call, and the
 * host it may call, each call checked against the permissions the extension was granted. Made per run; a script
 * never sees it, only the generated code does.
 */
final class Context
{
    private int $steps = 0;
    private int $calls = 0;
    private int $httpCalls = 0;
    private int $queries = 0;
    private int $bytes = 0;
    private float $startedAt;

    /**
     * @param list<string> $permissions what the extension was granted: `orders:read`, `http`, …
     */
    public function __construct(
        private readonly HostCatalog $catalog,
        private readonly Budget $budget = new Budget(),
        private readonly array $permissions = [],
        public readonly string $extension = '',
    ) {
        $this->startedAt = hrtime(true) / 1e9;
    }

    /** Counts a loop iteration or a function call; stops the run when the steps or the time are used up. */
    public function step(): void
    {
        if (++$this->steps > $this->budget->steps) {
            throw new BudgetExceeded(\sprintf('The script ran more than %d steps', $this->budget->steps));
        }
        if (($this->steps & 63) === 0 && hrtime(true) / 1e9 - $this->startedAt > $this->budget->seconds) {
            throw new BudgetExceeded(\sprintf('The script ran longer than %s seconds', $this->budget->seconds));
        }
    }

    /** Counts bytes a string or list took; the generated code calls it for what can grow (`~`, filters). */
    public function bytes(mixed $value): mixed
    {
        $size = \is_string($value) ? \strlen($value) : (\is_array($value) ? \count($value) * 16 : 0);
        if (($this->bytes += $size) > $this->budget->bytes) {
            throw new BudgetExceeded(\sprintf('The script made more than %d bytes of values', $this->budget->bytes));
        }

        return $value;
    }

    /**
     * Calls a host function by its dotted name as the script wrote it.
     *
     * @param list<mixed> $args
     */
    public function call(string $name, array $args): mixed
    {
        $resolved = $this->catalog->resolve($name);
        if ($resolved === null) {
            throw new ScriptError(\sprintf('Unknown function "%s"', $name));
        }
        [$function, $captured] = $resolved;
        $this->allow($function);
        $this->count($function);
        $this->step();

        return ($function->callable)(...$captured, ...$args);
    }

    private function allow(HostFunction $function): void
    {
        if ($function->needs !== null && !\in_array($function->needs, $this->permissions, true)) {
            throw new ScriptError(\sprintf('"%s" needs the permission "%s", which the extension was not granted', $function->name, $function->needs));
        }
    }

    private function count(HostFunction $function): void
    {
        if (++$this->calls > $this->budget->calls) {
            throw new BudgetExceeded(\sprintf('The script made more than %d calls', $this->budget->calls));
        }
        if ($function->counts === HostFunction::HTTP && ++$this->httpCalls > $this->budget->httpCalls) {
            throw new BudgetExceeded(\sprintf('The script made more than %d HTTP calls', $this->budget->httpCalls));
        }
        if ($function->counts === HostFunction::QUERY && ++$this->queries > $this->budget->queries) {
            throw new BudgetExceeded(\sprintf('The script made more than %d queries', $this->budget->queries));
        }
    }

    /** @return array{steps: int, calls: int, http_calls: int, queries: int, bytes: int, seconds: float} */
    public function used(): array
    {
        return ['steps' => $this->steps, 'calls' => $this->calls, 'http_calls' => $this->httpCalls, 'queries' => $this->queries, 'bytes' => $this->bytes, 'seconds' => round(hrtime(true) / 1e9 - $this->startedAt, 4)];
    }
}
