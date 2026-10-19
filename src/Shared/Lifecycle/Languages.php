<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Lifecycle;

/**
 * The language text is written in: this request's, or one chosen for a while, as an email to a customer in theirs.
 */
interface Languages
{
    /** This request's language: a WordPress locale such as de_DE. */
    public function current(): string;

    /**
     * Runs the work in the language, then goes back to the one before.
     *
     * @template T
     *
     * @param string|null  $locale null for the site's
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function in(?string $locale, \Closure $work): mixed;
}
