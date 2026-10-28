<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Application;

interface TranslatableTypes
{
    public function get(string $type): ?TranslatableType;
}
