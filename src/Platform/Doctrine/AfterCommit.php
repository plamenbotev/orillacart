<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

/**
 * Work that must wait until the database keeps what the transaction did, such as telling add-ons of an order:
 * it runs when the outermost transaction commits, and is dropped with a transaction or savepoint that rolls
 * back. Outside any transaction it runs at once. DoctrineTransactions marks where transactions begin and end.
 */
final class AfterCommit
{
    /** @var list<list<\Closure(): void>> one list of waiting work per open transaction, outermost first */
    private array $open = [];

    public function begin(): void
    {
        $this->open[] = [];
    }

    public function commit(): void
    {
        $work = array_pop($this->open) ?? [];
        if ($this->open !== []) {
            // A savepoint: its work now waits for the transaction around it.
            $this->open[array_key_last($this->open)] = [...$this->open[array_key_last($this->open)], ...$work];

            return;
        }
        $failure = null;
        foreach ($work as $run) {
            try {
                $run();
            } catch (\Throwable $e) {
                // The rest still runs: what one listener did wrong must not keep the news from the others.
                $failure ??= $e;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function rollBack(): void
    {
        array_pop($this->open);
    }

    /**
     * @param \Closure(): void $work
     */
    public function defer(\Closure $work): void
    {
        if ($this->open === []) {
            $work();

            return;
        }
        $this->open[array_key_last($this->open)][] = $work;
    }
}
