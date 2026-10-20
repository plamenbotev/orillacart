<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Shared;

use OrillaCart\Shared\Users\UserNames;

final class FixedUserNames implements UserNames
{
    /** @param array<int, string> $names */
    public function __construct(private readonly array $names = [7 => 'Mara']) {}

    public function names(array $ids): array
    {
        return array_intersect_key($this->names, array_flip($ids));
    }
}
