<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

/**
 * Whether templates render strictly now, on a live site too: a variable a template was not given fails instead
 * of being logged, as the email editor's preview wants.
 */
final class Strictness
{
    private int $depth = 0;

    public function on(): bool
    {
        return $this->depth > 0;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function during(\Closure $work): mixed
    {
        ++$this->depth;
        try {
            return $work();
        } finally {
            --$this->depth;
        }
    }
}
