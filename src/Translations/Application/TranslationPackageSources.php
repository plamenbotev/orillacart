<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Application;

interface TranslationPackageSources
{
    /**
     * @return list<TranslationPackageSource>
     */
    public function all(): array;

    public function get(string $kind): ?TranslationPackageSource;
}
