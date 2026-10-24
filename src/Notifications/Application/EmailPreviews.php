<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

/**
 * Whether the emails written now are previews (the template editor's, its test emails): they carry no reader's own
 * links, such as the one that takes the customer off the newsletter.
 */
final class EmailPreviews
{
    private bool $previewing = false;

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function during(\Closure $work): mixed
    {
        $was = $this->previewing;
        $this->previewing = true;
        try {
            return $work();
        } finally {
            $this->previewing = $was;
        }
    }

    public function active(): bool
    {
        return $this->previewing;
    }
}
