<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application;

/** Views as the shop's API answers them, as arrays: the same JSON in a webhook as in GET /orders/{id}. */
interface ViewNormalizer
{
    /** @return array<array-key, mixed> */
    public function normalize(object $view): array;
}
