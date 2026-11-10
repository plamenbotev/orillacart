<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application;

use OrillaCart\Scripting\Application\Dto\Budget;
use OrillaCart\Scripting\Application\Dto\Grant;
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
    private int $emails = 0;
    private int $queries = 0;
    private int $bytes = 0;
    private float $startedAt;

    public function __construct(
        private readonly HostCatalog $catalog,
        private readonly Budget $budget = new Budget(),
        public readonly Grant $grant = new Grant(),
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

    /** The seconds the run has left, host calls' time included; stops the run when there are none. */
    public function secondsLeft(): float
    {
        $left = $this->budget->seconds - (hrtime(true) / 1e9 - $this->startedAt);
        if ($left <= 0) {
            throw new BudgetExceeded(\sprintf('The script ran longer than %s seconds', $this->budget->seconds));
        }

        return $left;
    }

    /** Counts the bytes of text a host function made, as bytes() does. */
    public function text(string $text): string
    {
        $this->bytes($text);

        return $text;
    }

    /** The bytes the run may still make: what a filter checks before it makes them. */
    public function room(): int
    {
        return max(0, $this->budget->bytes - $this->bytes);
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
        // The time host calls waited (HTTP, mail, queries) is the script's own: none starts once it is spent.
        $this->secondsLeft();

        return ($function->callable)($this, ...$captured, ...$args);
    }

    private function allow(HostFunction $function): void
    {
        if ($function->needs !== null && !$this->grant->allows($function->needs)) {
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
        if ($function->counts === HostFunction::EMAIL && ++$this->emails > $this->budget->emails) {
            throw new BudgetExceeded(\sprintf('The script sent more than %d emails', $this->budget->emails));
        }
        if ($function->counts === HostFunction::QUERY && ++$this->queries > $this->budget->queries) {
            throw new BudgetExceeded(\sprintf('The script made more than %d queries', $this->budget->queries));
        }
    }

    /** @return array{steps: int, calls: int, http_calls: int, queries: int, emails: int, bytes: int, seconds: float} */
    public function used(): array
    {
        return ['steps' => $this->steps, 'calls' => $this->calls, 'http_calls' => $this->httpCalls, 'queries' => $this->queries, 'emails' => $this->emails, 'bytes' => $this->bytes, 'seconds' => round(hrtime(true) / 1e9 - $this->startedAt, 4)];
    }
}
