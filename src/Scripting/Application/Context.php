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

    /**
     * The functions running, outermost first, each with the line it is at (design 71): where an error happened
     * and what called it, in every run.
     *
     * @var list<array{fn: string, line: int, returned: bool}>
     */
    private array $frames = [];

    /** @var list<array{fn: string, line: int}>|null the stack as it was where an error left the innermost function */
    private ?array $failedAt = null;

    private ?Recorder $recorder = null;

    /** @var array<string, array<int, true>> the records this run made, by kind: what a test may go on to change */
    private array $made = [];

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

    /** Records this run for its debugger (design 71); the script's debug build reports its variables. */
    public function record(Recorder $recorder): void
    {
        $this->recorder = $recorder;
    }

    public function recorder(): ?Recorder
    {
        return $this->recorder;
    }

    /** A function of the script starts: its name as the script wrote it, and its line. */
    public function enter(string $function, int $line): void
    {
        $this->frames[] = ['fn' => $function, 'line' => $line, 'returned' => false];
        $this->recorder?->enter($function, $line, \count($this->frames));
    }

    /**
     * The script is at a line; the debug build gives its variables there.
     *
     * @param array<string, mixed>|null $vars
     */
    public function at(int $line, ?array $vars = null): void
    {
        $top = \count($this->frames) - 1;
        if ($top >= 0) {
            $this->frames[$top]['line'] = $line;
        }
        // Past an error the script caught: it is no longer where the run failed.
        $this->failedAt = null;
        if ($vars !== null) {
            $this->recorder?->line($line, $top + 1, $vars);
        }
    }

    /** What the function returns, on its way out. */
    public function result(mixed $value): mixed
    {
        $top = \count($this->frames) - 1;
        if ($top >= 0) {
            $this->frames[$top]['returned'] = true;
        }
        $this->recorder?->returned($value, $top + 1);

        return $value;
    }

    /** The function ends, by its return or by an error going through it. */
    public function leave(): void
    {
        $frame = array_pop($this->frames);
        if ($frame !== null && !$frame['returned']) {
            // An error goes through: the stack is kept as it was where the error left the innermost function.
            $this->failedAt ??= array_map(static fn(array $f): array => ['fn' => $f['fn'], 'line' => $f['line']], [...$this->frames, $frame]);
            $this->recorder?->unwound(\count($this->frames) + 1);
        }
    }

    /**
     * Where the run is, or where it failed: innermost first, each function with its line.
     *
     * @return list<array{fn: string, line: int}>
     */
    public function stack(): array
    {
        $frames = $this->failedAt ?? array_map(static fn(array $f): array => ['fn' => $f['fn'], 'line' => $f['line']], $this->frames);

        return array_reverse($frames);
    }

    /** The line the run is at, or failed at; 0 before the first. */
    public function line(): int
    {
        return $this->stack()[0]['line'] ?? 0;
    }

    /** What `debug(value)` marked: kept in a recording, nothing otherwise. */
    public function noted(mixed $value): mixed
    {
        $this->recorder?->noted($value, $this->line(), \count($this->frames));

        return $value;
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
        // A recording keeps the calls that reach the shop or past it, not the language's own helpers.
        if ($this->recorder === null || ($function->needs === null && $function->counts === HostFunction::CALL && $function->writes !== true)) {
            return ($function->callable)($this, ...$captured, ...$args);
        }
        try {
            $result = ($function->callable)($this, ...$captured, ...$args);
        } catch (\Throwable $e) {
            $this->recorder->call($name, $args, null, $e->getMessage(), $this->line(), \count($this->frames));

            throw $e;
        }
        $this->recorder->call($name, $args, $result, null, $this->line(), \count($this->frames));

        return $result;
    }

    /**
     * A host function that sends mail as well as what it counts as (an order placed with the customer emailed):
     * the extension needs `email:send`, and the mail counts against the run's emails as shop.email.send's does.
     */
    public function mails(string $name): void
    {
        if (!$this->grant->allows('email:send')) {
            throw new ScriptError(\sprintf('"%s" emails only with the permission "email:send", which the extension was not granted', $name));
        }
        if (++$this->emails > $this->budget->emails) {
            throw new BudgetExceeded(\sprintf('The script sent more than %d emails', $this->budget->emails));
        }
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

    /** Notes a record this run made (a test's product), which a function may let it change where others it may not. */
    public function made(string $kind, int $id): void
    {
        $this->made[$kind][$id] = true;
    }

    public function didMake(string $kind, int $id): bool
    {
        return isset($this->made[$kind][$id]);
    }

    /** @return array{steps: int, calls: int, http_calls: int, queries: int, emails: int, bytes: int, seconds: float} */
    public function used(): array
    {
        return ['steps' => $this->steps, 'calls' => $this->calls, 'http_calls' => $this->httpCalls, 'queries' => $this->queries, 'emails' => $this->emails, 'bytes' => $this->bytes, 'seconds' => round(hrtime(true) / 1e9 - $this->startedAt, 4)];
    }
}
