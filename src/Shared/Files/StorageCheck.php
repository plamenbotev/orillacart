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
    /** The folder remembered is marked as another install's or site's: the site moved, or it is a copy. */
    public const string ELSEWHERE = 'elsewhere';
    /** The folder remembered is gone. */
    public const string MISSING = 'missing';
    /** The answers to a claim: the folder is this site's (it moved), or this site starts a new one (a copy). */
    public const string ADOPT = 'adopt';
    public const string START_NEW = 'new';
    public const array ANSWERS = [self::ADOPT, self::START_NEW];

    /**
     * @param bool|null $reachable true when a test file in the folder could be fetched over HTTP; null when not tested
     *                             (the folder is outside the site, or the test could not run)
     * @param string|null $claim   ELSEWHERE or MISSING while the shop waits for the shop manager to say whose the
     *                             folder is (PrivateFiles::answer()); null when it is this site's
     */
    public function __construct(
        public string $location,
        public string $directory,
        public ?bool $reachable,
        public string $nginxRule,
        public ?string $claim = null,
    ) {}
}
