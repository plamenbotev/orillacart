<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Notifications;

use OrillaCart\Notifications\Application\PlainText;
use PHPUnit\Framework\TestCase;

final class PlainTextTest extends TestCase
{
    public function testBlocksAreLinesCellsSitSideBySideAndLinksKeepTheirAddress(): void
    {
        $html = <<<'HTML'
            <!DOCTYPE html><html><head><title>Ignored</title><style>p { color: red; }</style></head><body>
            <h1>Thank you</h1>
            <p>Your order &amp; its <a href="https://shop.test/r?a=1&amp;b=2">receipt</a>.<br>See you.</p>
            <table><tr><td>Lamp × 2</td><td>8.00&nbsp;EUR</td></tr></table>
            <p><a href="https://shop.test/">https://shop.test/</a></p>
            </body></html>
            HTML;

        self::assertSame(
            "Thank you\n\nYour order & its receipt (https://shop.test/r?a=1&b=2).\nSee you.\n\nLamp × 2 8.00 EUR\n\nhttps://shop.test/\n",
            PlainText::fromHtml($html),
        );
    }
}
