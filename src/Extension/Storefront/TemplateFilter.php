<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Storefront;

/**
 * A filter every storefront template may use, the sandboxed ones included: `{{ 'hero.title'|t }}`. What it answers
 * is escaped as any value is, unless it says it is HTML. A name the shop's own filters have is refused, and logged.
 * Since version 24.
 */
interface TemplateFilter
{
    /** Lowercase letters, digits and underscores, up to 40, starting with a letter: "t", "theme_word". */
    public function name(): string;

    /**
     * @param mixed       $value     what is filtered
     * @param list<mixed> $arguments what the template passes: `|t(count)` gives [count]
     */
    public function apply(mixed $value, array $arguments): mixed;

    /** Whether what it answers is HTML the template prints as it is; false escapes it. */
    public function isHtml(): bool;
}
