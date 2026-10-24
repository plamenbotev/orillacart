<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Notifications;

use OrillaCart\Notifications\Application\EmailWriter;
use OrillaCart\Notifications\Application\UnsubscribeLinks;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class EmailWriterTest extends TestCase
{
    public function testAPreviewDoesNotCarryTheCustomersLinkOffTheNewsletter(): void
    {
        $shop = new TestShop();
        $links = new class implements UnsubscribeLinks {
            public function for(string $email): string
            {
                return "https://shop.example/unsubscribe?who={$email}&sig=secret";
            }
        };
        $writer = new EmailWriter($shop->emailTemplates, $shop->emailSettings, $shop->site, $shop->languages, $links);
        $write = static fn(): string => $writer->write(EmailKind::AccountWelcome->template(), 'account_welcome', 'ada@example.com', null, ['first_name' => 'Ada', 'account_url' => 'https://shop.example/account'])->html;

        self::assertStringContainsString('sig=secret', $write(), 'the email itself carries it');
        $preview = $writer->previewing($write);
        self::assertStringNotContainsString('sig=secret', $preview);
        self::assertSame(2, substr_count($preview, 'href="' . $shop->site->url() . '"'), 'the preview shows where the link is, beside the shop\'s own');
    }
}
