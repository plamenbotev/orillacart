<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Notifications;

use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\SaveVariation;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Domain\Event\StockChanged;
use OrillaCart\Notifications\Application\BackInStock;
use OrillaCart\Notifications\Application\Dto\AskBackInStock;
use OrillaCart\Notifications\Application\SendBackInStock;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailRuleViolation;
use OrillaCart\Notifications\Domain\EmailSettings;
use OrillaCart\Notifications\Infrastructure\Events\EmailWhenBackInStock;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Notifications\InMemoryBackInStockRequests;
use PHPUnit\Framework\TestCase;

final class BackInStockTest extends TestCase
{
    private TestShop $shop;
    private InMemoryBackInStockRequests $requests;
    private BackInStock $backInStock;
    private int $room;
    private int $small;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->room = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30'));
        $this->shop->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($this->room, 0)]));
        $this->shop->content->add(11, 'Hoodie');
        $hoodie = $this->shop->products->save(11, new SaveProduct('40', attributes: [new SaveAttribute('Size', [new SaveOption('S'), new SaveOption('M')])]));
        $this->small = $this->shop->products->addVariation(11, new SaveVariation([$hoodie->attributes[0]->options[0]->id], '41'))->id;
        $this->shop->stock->setLevels(StockItemRef::variation($this->small), new SetStockLevels([new SetStockLevel($this->room, 0)]));
        $this->requests = new InMemoryBackInStockRequests();
        $this->backInStock = new BackInStock($this->requests, $this->shop->catalog, $this->shop->emailSettings, $this->shop->writer, $this->shop->mail, $this->shop->site, $this->shop->jobs->jobs, $this->shop->clock);
        $this->shop->jobs->with(new SendBackInStock($this->backInStock, $this->shop->clock));
        $this->shop->events->addListener(StockChanged::class, new EmailWhenBackInStock($this->backInStock)->stockChanged(...));
    }

    public function testThoseWhoAskedHearOnceWhenItIsBackInTheirLanguage(): void
    {
        $this->backInStock->ask(new AskBackInStock(10, 'Ada@Example.com'), 'de_DE');
        $this->backInStock->ask(new AskBackInStock(10, 'ada@example.com'), 'de_DE');
        $this->backInStock->ask(new AskBackInStock(11, 'grace@example.com', $this->small), null);
        self::assertCount(2, $this->requests->requests, 'asked twice, kept once');

        $this->shop->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($this->room, 3)]));
        $this->shop->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($this->room, 5)]));

        self::assertSame(['ada@example.com: [Lamp Shop] Lamp is back in stock'], $this->shop->mail->summary(), 'once');
        self::assertStringContainsString('href="https://shop.test/?p=10"', $this->shop->mail->emails[0]->html);
        self::assertContains('de_DE', $this->shop->languages->switched);

        $this->shop->stock->setLevels(StockItemRef::variation($this->small), new SetStockLevels([new SetStockLevel($this->room, 1)]));
        self::assertSame('grace@example.com: [Lamp Shop] Hoodie is back in stock', $this->shop->mail->summary()[1]);
        self::assertStringContainsString('Hoodie – S is back', $this->shop->mail->emails[1]->html);
        self::assertSame([], $this->requests->requests, 'forgotten once written to');
    }

    public function testOnlyWhatIsSoldOutCanBeAskedForAndTheShopCanTurnItOff(): void
    {
        $this->shop->stock->setLevels(StockItemRef::product(10), new SetStockLevels([new SetStockLevel($this->room, 2)]));
        try {
            $this->backInStock->ask(new AskBackInStock(10, 'ada@example.com'), null);
            self::fail('in stock');
        } catch (EmailRuleViolation $e) {
            self::assertSame('orillacart_not_sold_out', $e->errorCode);
        }

        $this->shop->emailSettings->save(new EmailSettings(disabled: [EmailKind::BackInStock]));
        $this->expectException(EmailRuleViolation::class);
        $this->backInStock->ask(new AskBackInStock(11, 'ada@example.com', $this->small), null);
    }

    public function testRequestsAreForgottenWithThePersonAndAfterHalfAYear(): void
    {
        $this->backInStock->ask(new AskBackInStock(10, 'ada@example.com'), null);
        $this->backInStock->ask(new AskBackInStock(11, 'grace@example.com', $this->small), null);

        self::assertSame(1, $this->backInStock->forgetEmail('ADA@example.com'));
        $this->shop->clock->modify('+7 months');
        self::assertSame(1, $this->backInStock->forgetOld());
        self::assertSame([], $this->requests->requests);
    }
}
