<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Application\Dto\PackageArchive;
use OrillaCart\Packages\Application\Dto\PackageExport;
use OrillaCart\Packages\Domain\PackageRuleViolation;

/**
 * Extensions as zip files (design 58): the files at the zip's root or in one folder around them, with an
 * optional extension.json naming the extension and carrying its translations.
 */
interface PackageArchives
{
    /** The extension file's name in a zip. */
    public const string MANIFEST = 'extension.json';

    /** The largest zip taken. */
    public const int MAX_SIZE = 10 * 1024 * 1024;

    /** The most entries a zip may have, files skipped included. */
    public const int MAX_ENTRIES = 500;

    /**
     * @throws PackageRuleViolation for a zip that does not open, is too large, or holds a file the package refuses
     */
    public function read(string $path): PackageArchive;

    /** The zip's bytes. */
    public function write(PackageExport $export): string;
}
