<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shipping;

use OrillaCart\Shipping\Application\Consignments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A courier's label reaches the admin as a PDF or an image to print, or as a file to download: never as a page. */
final class LabelTypeTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function types(): iterable
    {
        yield 'a PDF' => ['application/pdf', 'application/pdf', 'pdf'];
        yield 'a PNG' => ['image/png', 'image/png', 'png'];
        yield 'a GIF' => ['image/gif', 'image/gif', 'gif'];
        yield 'a JPEG' => ['image/jpeg', 'image/jpeg', 'jpg'];
        yield 'a JPEG by its other name' => ['image/jpg', 'image/jpeg', 'jpg'];
        yield 'with a parameter and capitals' => ['Application/PDF; charset=binary', 'application/pdf', 'pdf'];
        yield 'ZPL' => ['application/zpl', 'application/octet-stream', 'zpl'];
        yield 'HTML' => ['text/html', 'application/octet-stream', 'bin'];
        yield 'SVG' => ['image/svg+xml', 'application/octet-stream', 'bin'];
        yield 'XHTML' => ['application/xhtml+xml', 'application/octet-stream', 'bin'];
        yield 'nothing' => ['', 'application/octet-stream', 'bin'];
    }

    #[DataProvider('types')]
    public function testOnlyPdfAndImagesKeepTheirType(string $given, string $type, string $extension): void
    {
        self::assertSame([$type, $extension], Consignments::labelType($given));
    }
}
