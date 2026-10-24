<?php

declare(strict_types=1);

namespace OrillaCart\Search\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class SearchFilterNotFound extends NotFound
{
    public function __construct(int $id)
    {
        parent::__construct('There is no filter %d.', 'orillacart_filter_not_found', values: [$id]);
    }
}
