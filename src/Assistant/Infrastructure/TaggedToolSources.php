<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure;

use OrillaCart\Assistant\Application\ToolSource;
use OrillaCart\Assistant\Application\ToolSources;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The sources of tools the container found by their tag.
 */
final readonly class TaggedToolSources implements ToolSources
{
    /** @var list<ToolSource> */
    private array $sources;

    /** @param iterable<ToolSource> $sources */
    public function __construct(
        #[AutowireIterator(ToolSource::TAG)]
        iterable $sources,
    ) {
        $this->sources = iterator_to_array($sources, false);
    }

    public function all(): array
    {
        return $this->sources;
    }
}
