<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Infrastructure;

use OrillaCart\Customers\Application\PersonalDataSource;
use OrillaCart\Customers\Application\PersonalDataSources;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** The areas' personal data, as the container found them by their tag. */
final readonly class TaggedPersonalDataSources implements PersonalDataSources
{
    /**
     * @param iterable<PersonalDataSource> $sources
     */
    public function __construct(
        #[AutowireIterator(PersonalDataSource::TAG)]
        private iterable $sources,
    ) {}

    public function of(string $email, ?int $customerId): array
    {
        $items = [];
        foreach ($this->sources as $source) {
            array_push($items, ...$source->personalData($email, $customerId));
        }

        return $items;
    }
}
