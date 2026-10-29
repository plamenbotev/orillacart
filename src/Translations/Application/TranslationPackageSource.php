<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Application;

use OrillaCart\Translations\Application\Dto\TranslationPackage;

/**
 * An area's objects as packages of words for a translation service: carriers, payment methods, forms. Each says
 * which words an object has; the translations table keeps what comes back.
 */
interface TranslationPackageSource
{
    public const string TAG = 'orillacart.translation_package_source';

    /** The kind's slug, such as "carrier": a package is known by it and the object's id. */
    public function kind(): string;

    /** The kind as translators read it, such as "OrillaCart carrier": in English always, the service may know packages by it. */
    public function title(): string;

    /** The object's words; null for one that is gone or has none. */
    public function package(int $id): ?TranslationPackage;

    /**
     * Every object of the kind, to register them all.
     *
     * @return iterable<int>
     */
    public function ids(): iterable;
}
