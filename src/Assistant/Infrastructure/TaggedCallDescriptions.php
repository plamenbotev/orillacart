<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure;

use OrillaCart\Assistant\Application\CallDescription;
use OrillaCart\Assistant\Application\CallDescriptions;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The descriptions of calls the container found by their tag.
 */
final readonly class TaggedCallDescriptions implements CallDescriptions
{
    /** @var list<CallDescription> */
    private array $descriptions;

    /** @param iterable<CallDescription> $descriptions */
    public function __construct(
        #[AutowireIterator(CallDescription::TAG)]
        iterable $descriptions,
    ) {
        $this->descriptions = iterator_to_array($descriptions, false);
    }

    public function all(): array
    {
        return $this->descriptions;
    }
}
