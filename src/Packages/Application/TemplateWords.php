<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

/**
 * An extension's templates read as the storefront compiles them (design 57): the words their `__`, `_x`, `_n`
 * and `_nx` calls mark for translation, and what stops one compiling, words not written as strings among it.
 */
interface TemplateWords
{
    /**
     * The words of a template, by the line they are on; none for a template that does not compile.
     *
     * @return list<array{text: string, plural: ?string, context: string, line: int}>
     */
    public function of(string $path, string $source): array;

    /**
     * Why the template does not compile, and on which line; null when it does.
     *
     * @return array{line: int, message: string}|null
     */
    public function problem(string $extension, string $path, string $source): ?array;

    /**
     * The files the template's `asset()` calls name (design 59), with the line of each; none for a template that
     * does not compile.
     *
     * @return list<array{name: string, line: int}>
     */
    public function assets(string $path, string $source): array;
}
