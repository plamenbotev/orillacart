<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Extension;

/**
 * What add-ons failed to do in this request, besides the log: the order being placed notes it for the shop, and
 * refuses when one that fails closed did.
 */
final class ExtensionFailures
{
    /** @var list<array{what: string, closed: bool}> */
    private array $failures = [];

    /** @param string $what for the shop, as an order note says it */
    public function record(string $what, bool $closed): void
    {
        $this->failures[] = ['what' => $what, 'closed' => $closed];
    }

    /** Where to read from: what fails after this. */
    public function mark(): int
    {
        return \count($this->failures);
    }

    /** @return list<string> what failed since the mark, each once */
    public function since(int $mark): array
    {
        return array_values(array_unique(array_column(\array_slice($this->failures, $mark), 'what')));
    }

    public function closedSince(int $mark): bool
    {
        return \in_array(true, array_column(\array_slice($this->failures, $mark), 'closed'), true);
    }
}
