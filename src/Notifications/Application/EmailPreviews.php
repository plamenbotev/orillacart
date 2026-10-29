<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

/**
 * Whether the emails written now are previews (the template editor's, its test emails): they carry no reader's own
 * links, such as the one that takes the customer off the newsletter. A preview of a language's template is written
 * in that language, whoever the sample's reader is.
 */
final class EmailPreviews
{
    private bool $previewing = false;
    private ?string $locale = null;

    /**
     * @template T
     *
     * @param \Closure(): T $work
     * @param string|null   $locale the language every email is written in; null for each reader's own
     *
     * @return T
     */
    public function during(\Closure $work, ?string $locale = null): mixed
    {
        [$was, $wasIn] = [$this->previewing, $this->locale];
        $this->previewing = true;
        $this->locale = $locale;
        try {
            return $work();
        } finally {
            [$this->previewing, $this->locale] = [$was, $wasIn];
        }
    }

    public function active(): bool
    {
        return $this->previewing;
    }

    /** The language a preview is written in; null for the reader's. */
    public function locale(): ?string
    {
        return $this->locale;
    }
}
