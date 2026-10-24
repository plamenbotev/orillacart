<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Infrastructure;

use OrillaCart\Tax\Application\TaxGroupUse;
use OrillaCart\Tax\Application\TaxGroupUses;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Every area's uses of tax groups, as the container found them by their tag. */
final readonly class TaggedTaxGroupUses implements TaxGroupUses
{
    /**
     * @param iterable<TaxGroupUse> $uses
     */
    public function __construct(
        #[AutowireIterator(TaxGroupUse::TAG)]
        private iterable $uses,
    ) {}

    public function of(int $groupId): array
    {
        $found = [];
        foreach ($this->uses as $use) {
            $what = $use->uses($groupId);
            if ($what !== null) {
                $found[] = $what;
            }
        }

        return $found;
    }
}
