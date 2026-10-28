<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Infrastructure;

use OrillaCart\Translations\Application\TranslatableType;
use OrillaCart\Translations\Application\TranslatableTypes;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Every area's translatable types, as the container found them by their tag. */
final readonly class TaggedTranslatableTypes implements TranslatableTypes
{
    /**
     * @param iterable<TranslatableType> $types
     */
    public function __construct(
        #[AutowireIterator(TranslatableType::TAG)]
        private iterable $types,
    ) {}

    public function get(string $type): ?TranslatableType
    {
        foreach ($this->types as $candidate) {
            if ($candidate->type() === $type) {
                return $candidate;
            }
        }

        return null;
    }
}
