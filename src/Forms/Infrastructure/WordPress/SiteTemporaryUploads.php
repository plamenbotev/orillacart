<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Infrastructure\WordPress;

use OrillaCart\Forms\Application\FormUploads;
use OrillaCart\Forms\Application\TemporaryUploads;

/**
 * Temporary files of uploads in the system's temporary directory, named for the current site.
 */
final readonly class SiteTemporaryUploads implements TemporaryUploads
{
    public function prefix(): string
    {
        return FormUploads::temporaryPrefix(get_current_blog_id());
    }

    public function create(): string
    {
        $path = tempnam(sys_get_temp_dir(), $this->prefix());
        if ($path === false) {
            throw new \RuntimeException('No temporary file for the upload.');
        }

        return $path;
    }
}
