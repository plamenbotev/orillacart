<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Persistence;

/**
 * The entities this process loaded and keeps. A worker that runs for long forgets them between batches, so it
 * reads what other processes saved since.
 */
interface LoadedEntities
{
    /** Forgets every loaded entity; do not call it with unsaved changes. */
    public function forget(): void;
}
