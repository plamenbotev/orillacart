<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shared\Files;

use OrillaCart\Shared\Files\ByteRange;
use PHPUnit\Framework\TestCase;

final class ByteRangeTest extends TestCase
{
    public function testOneRangeOfTheFile(): void
    {
        self::assertEquals(new ByteRange(100, 999), ByteRange::of('bytes=100-', 1000), 'the rest');
        self::assertEquals(new ByteRange(0, 499), ByteRange::of('bytes=0-499', 1000));
        self::assertEquals(new ByteRange(500, 999), ByteRange::of('bytes=500-5000', 1000), 'up to the end');
        self::assertEquals(new ByteRange(900, 999), ByteRange::of('bytes=-100', 1000), 'the last 100');
        self::assertEquals(new ByteRange(0, 999), ByteRange::of('bytes=-5000', 1000));
        self::assertSame(400, new ByteRange(100, 499)->length());
    }

    public function testWhatIsNotOneRangeGetsTheWholeFileAndPastTheEndNothing(): void
    {
        foreach (['', 'bytes=0-10,20-30', 'items=0-10', 'bytes=-', 'bytes=50-10'] as $header) {
            self::assertNull(ByteRange::of($header, 1000), $header);
        }
        self::assertFalse(ByteRange::of('bytes=1000-', 1000));
        self::assertFalse(ByteRange::of('bytes=-0', 1000));
    }

    public function testOnlyARangeFromPastTheStartResumes(): void
    {
        self::assertTrue(ByteRange::resumes('bytes=100-'));
        self::assertTrue(ByteRange::resumes('bytes=-100'));
        foreach (['', 'bytes=0-', 'bytes=0-499', 'bytes=50-10', 'bytes=0-10,20-30'] as $header) {
            self::assertFalse(ByteRange::resumes($header), $header);
        }
    }
}
