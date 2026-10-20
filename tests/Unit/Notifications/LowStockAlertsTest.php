<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Notifications;

use OrillaCart\Catalog\Application\Dto\LowStockView;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\LowStock;
use OrillaCart\Catalog\Application\StockItemNames;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Notifications\Application\LowStockAlerts;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettings;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Notifications\InMemoryAlertedStock;
use PHPUnit\Framework\TestCase;

final class LowStockAlertsTest extends TestCase
{
    private TestShop $shop;
    private LowStock $low;
    private LowStockAlerts $alerts;
    private int $sofia;
    private int $plovdiv;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->sofia = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $this->plovdiv = $this->shop->stockrooms->create(new SaveStockroom('Plovdiv', lowStockThreshold: 10))->id;
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', sku: 'LAMP'));
        $this->shop->content->add(11, 'Bulb');
        $this->shop->products->save(11, new SaveProduct('3', lowStockThreshold: 0));
        $this->shop->content->add(12, 'Poster');
        $this->shop->products->save(12, new SaveProduct('5', stockMode: 'always_available'));
        $this->level(10, $this->sofia, 2);
        $this->level(10, $this->plovdiv, 8);
        $this->level(11, $this->sofia, 1);
        $this->level(12, $this->sofia, 0);
        $this->low = new LowStock($this->shop->productRecords, new StockItemNames($this->shop->productRecords, $this->shop->content), $this->shop->stock, $this->shop->stockrooms, $this->shop->settingsService);
        $this->alerts = new LowStockAlerts($this->low, new InMemoryAlertedStock(), $this->shop->emailSettings, $this->shop->writer, $this->shop->mail, $this->shop->logger);
    }

    public function testTheItemsThresholdComesBeforeTheStockroomsAndTheShops(): void
    {
        self::assertSame([
            ['Lamp', 'Plovdiv', 8, 10],
            ['Lamp', 'Sofia', 2, 2],
        ], array_map(static fn(LowStockView $item): array => [$item->name, $item->stockroom, $item->quantity, $item->threshold], $this->low->items()), 'the bulb wants to hear only when it is out; the poster is always available');
    }

    public function testTheShopHearsOnceADayOfWhatRanLowSince(): void
    {
        self::assertTrue($this->alerts->alert());
        self::assertSame(['owner@shop.test: [Lamp Shop] Stock runs low'], $this->shop->mail->summary());
        self::assertStringContainsString('LAMP', $this->shop->mail->emails[0]->html);
        self::assertFalse($this->alerts->alert(), 'nothing new');

        $this->level(11, $this->sofia, 0);
        self::assertTrue($this->alerts->alert(), 'the bulb is out');
        self::assertStringContainsString('<strong>Bulb</strong>', $this->shop->mail->emails[1]->html, 'marked new');
        self::assertStringNotContainsString('<strong>Lamp</strong>', $this->shop->mail->emails[1]->html);

        $this->level(10, $this->sofia, 5);
        self::assertFalse($this->alerts->alert(), 'restocked');
        $this->level(10, $this->sofia, 1);
        self::assertTrue($this->alerts->alert(), 'low again');
    }

    public function testTheShopTurnsTheAlertOff(): void
    {
        $this->shop->emailSettings->save(new EmailSettings(disabled: [EmailKind::LowStock]));

        self::assertFalse($this->alerts->alert());
        self::assertSame([], $this->shop->mail->summary());
    }

    private function level(int $product, int $stockroom, int $quantity): void
    {
        $levels = $this->shop->stock->levelsOf([StockItemRef::product($product)])["product:{$product}"] ?? [];
        $levels[$stockroom] = $quantity;
        $this->shop->stock->setLevels(StockItemRef::product($product), new SetStockLevels(array_map(static fn(int $room, int $count): SetStockLevel => new SetStockLevel($room, $count), array_keys($levels), array_values($levels))));
    }
}
