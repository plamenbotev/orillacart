<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

/**
 * What in an area is taxed by a tax group, such as products or shipping rates: a group in use is not deleted,
 * as what it taxed would then sell with no tax at all.
 */
interface TaxGroupUse
{
    public const string TAG = 'orillacart.tax_group_use';

    /** What uses the group, in a few words ("3 products"); null when nothing does. */
    public function uses(int $groupId): ?string;
}
