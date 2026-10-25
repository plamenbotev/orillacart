<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Infrastructure;

use OrillaCart\Setup\Application\ReadinessCheck;
use OrillaCart\Setup\Application\ReadinessChecks;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class TaggedReadinessChecks implements ReadinessChecks
{
    /** @param iterable<ReadinessCheck> $checks */
    public function __construct(
        #[AutowireIterator(ReadinessCheck::TAG)]
        private iterable $checks,
    ) {}

    public function all(): array
    {
        return iterator_to_array($this->checks, false);
    }
}
