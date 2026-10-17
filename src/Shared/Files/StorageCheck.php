<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Files;

/**
 * Where private files are kept and whether the web server hands them out by itself, which it must not.
 */
final readonly class StorageCheck
{
    public const string OUTSIDE_WEB_ROOT = 'outside_web_root';
    public const string UPLOADS = 'uploads';
    public const string CONFIGURED = 'configured';

    /**
     * @param bool|null $reachable true when a test file in the folder could be fetched over HTTP; null when not tested
     *                             (the folder is outside the site, or the test could not run)
     */
    public function __construct(
        public string $location,
        public string $directory,
        public ?bool $reachable,
        public string $nginxRule,
    ) {}
}
