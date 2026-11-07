<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Domain;

use OrillaCart\Shared\Domain\NotFound;

final class PackageNotFound extends NotFound
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct('There is no extension "%s".', 'orillacart_extension_not_found', values: [$slug]);
    }
}
