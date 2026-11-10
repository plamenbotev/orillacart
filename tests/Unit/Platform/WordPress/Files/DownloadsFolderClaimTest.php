<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\WordPress\Files;

use OrillaCart\Platform\WordPress\Files\LocalPrivateFiles;
use OrillaCart\Shared\Files\StorageCheck;
use PHPUnit\Framework\TestCase;

final class DownloadsFolderClaimTest extends TestCase
{
    private const string OURS = "site 1 of 0123456789abcdef\n";
    private const string FOLDER = '/srv/orillacart-downloads/0123456789abcdef-site-1';

    public function testAFolderMarkedAsThisSitesIsUsed(): void
    {
        self::assertNull(LocalPrivateFiles::claim(self::FOLDER, self::FOLDER, self::OURS, self::OURS, false));
    }

    public function testAnUnmarkedFolderIsUsedOnlyWhereThisSiteMakesOne(): void
    {
        self::assertNull(LocalPrivateFiles::claim(self::FOLDER, self::FOLDER, null, self::OURS, true));
        self::assertSame([StorageCheck::ELSEWHERE, self::FOLDER], LocalPrivateFiles::claim(self::FOLDER, self::FOLDER, null, self::OURS, false));
    }

    public function testAFolderMarkedAsAnotherInstallsIsKeptAndAskedAbout(): void
    {
        self::assertSame([StorageCheck::ELSEWHERE, self::FOLDER], LocalPrivateFiles::claim(self::FOLDER, self::FOLDER, "site 1 of fedcba9876543210\n", self::OURS, true), 'a copy, or the same site moved');
        self::assertSame([StorageCheck::ELSEWHERE, self::FOLDER], LocalPrivateFiles::claim(self::FOLDER, self::FOLDER, "site 2 of 0123456789abcdef\n", self::OURS, true), 'another site of the network');
    }

    public function testAFolderFoundWhereTheSiteMovedToIsAskedAboutAndOneFoundNowhereIsMissing(): void
    {
        $movedTo = '/var/www/new/wp-content/uploads/orillacart-' . str_repeat('a', 64);
        self::assertSame([StorageCheck::ELSEWHERE, $movedTo], LocalPrivateFiles::claim('/var/www/old/wp-content/uploads/orillacart-' . str_repeat('a', 64), $movedTo, self::OURS, self::OURS, true));
        self::assertSame([StorageCheck::MISSING, self::FOLDER], LocalPrivateFiles::claim(self::FOLDER, null, null, self::OURS, false));
    }
}
