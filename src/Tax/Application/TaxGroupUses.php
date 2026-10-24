<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

/** What every area taxes by a tax group. */
interface TaxGroupUses
{
    /** @return list<string> what uses it, in a few words each ("3 products", "2 shipping rates") */
    public function of(int $groupId): array;
}
