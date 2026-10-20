<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Users;

/**
 * The names of the site's users, as screens show who did something.
 */
interface UserNames
{
    /**
     * @param list<int> $ids
     *
     * @return array<int, string> by id; a user that is gone is left out
     */
    public function names(array $ids): array;
}
