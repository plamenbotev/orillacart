<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

final readonly class WriteFile
{
    public function __construct(public string $content = '') {}
}
