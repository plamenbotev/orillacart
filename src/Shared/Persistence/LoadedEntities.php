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

    /**
     * After a statement that changed rows without going through their entities (a DQL UPDATE or DELETE): the loaded
     * entities of these classes are read again, with the collections they own, and one whose row is gone is
     * forgotten. What the request goes on to read or save is then what the database holds, not what it held before
     * the statement. Do not call it with unsaved changes to those entities: they would be lost.
     *
     * @param class-string ...$classes
     */
    public function reread(string ...$classes): void;
}
