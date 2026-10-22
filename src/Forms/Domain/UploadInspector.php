<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Domain;

/**
 * Looks inside the files shoppers send: what they really are, and a copy safe to keep.
 */
interface UploadInspector
{
    /** The type the content shows ("image/png"), whatever the file is called; null when it cannot tell. */
    public function type(string $path): ?string;

    /**
     * A copy safe to keep, at a new path: a picture decoded and written again (nothing hidden in it, no metadata),
     * a PDF as it is once nothing in it asks to run.
     *
     * @throws FormRuleViolation with what is wrong with the file
     */
    public function clean(string $path, string $type): string;

    /** Pictures can be written again: GD or Imagick is there. Without, file fields take PDFs only. */
    public function takesImages(): bool;
}
