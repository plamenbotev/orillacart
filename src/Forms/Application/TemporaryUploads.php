<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Application;

/**
 * The temporary files uploads pass through on their way in (a request's file before the fields hold, a copy being
 * cleaned), named for the site: a sweeper deletes those a failed request left, and only its own site's.
 */
interface TemporaryUploads
{
    /** @return string the start of the files' names, FormUploads::temporaryPrefix() of the site */
    public function prefix(): string;

    /**
     * @return string the path of a new, empty temporary file
     *
     * @throws \RuntimeException when none can be made
     */
    public function create(): string;
}
