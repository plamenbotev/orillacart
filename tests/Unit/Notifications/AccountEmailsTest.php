<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Notifications;

use OrillaCart\Notifications\Application\AccountEmails;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class AccountEmailsTest extends TestCase
{
    private TestShop $shop;
    private AccountEmails $emails;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->emails = new AccountEmails($this->shop->templates, $this->shop->mail, $this->shop->emailSettings, $this->shop->site, $this->shop->languages, $this->shop->logger);
    }

    public function testANewAccountIsWelcomedWithTheWayToIt(): void
    {
        $this->emails->welcome('ada@example.com', 'Ada', 'https://shop.test/account/');

        self::assertSame(['ada@example.com: [Lamp Shop] Your account is ready'], $this->shop->mail->summary());
        self::assertStringContainsString('Welcome, Ada', $this->shop->mail->emails[0]->html);
        self::assertStringContainsString('href="https://shop.test/account/"', $this->shop->mail->emails[0]->html);
        self::assertSame('account_welcome', $this->shop->mail->emails[0]->kind);
    }

    public function testTheShopTurnsTheWelcomeOff(): void
    {
        $this->shop->emailSettings->save(new EmailSettings(disabled: [EmailKind::AccountWelcome]));

        $this->emails->welcome('ada@example.com', 'Ada', 'https://shop.test/account/');

        self::assertSame([], $this->shop->mail->summary());
    }
}
