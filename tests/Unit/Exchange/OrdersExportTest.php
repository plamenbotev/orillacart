<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Exchange;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Exchange\Application\ColumnChoice;
use OrillaCart\Exchange\Application\CsvFile;
use OrillaCart\Exchange\Application\Dto\ChooseColumns;
use OrillaCart\Exchange\Application\Dto\ColumnView;
use OrillaCart\Exchange\Application\JsonFile;
use OrillaCart\Exchange\Application\OrderColumns;
use OrillaCart\Exchange\Application\OrdersExport;
use OrillaCart\Exchange\Domain\ExchangeRuleViolation;
use OrillaCart\Extension\Order\OrderColumn;
use OrillaCart\Extension\Order\OrderSummary;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Application\OrderSummaries;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Exchange\EnglishColumnNames;
use OrillaCart\Tests\Fixtures\Exchange\InMemoryExportChoices;
use PHPUnit\Framework\TestCase;

final class OrdersExportTest extends TestCase
{
    private TestShop $shop;
    private OrdersExport $export;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->export = new OrdersExport(new OrderColumns($this->shop->extensions, $this->shop->logger), new ColumnChoice(new InMemoryExportChoices(), new EnglishColumnNames()), $this->shop->orders, new OrderSummaries($this->shop->orders, $this->shop->orderRecords), $this->shop->logger);
    }

    public function testTheChosenColumnsOfEveryOrderTheListFindsGoToTheFile(): void
    {
        $first = $this->place('ada@example.com', 'Ada');
        $second = $this->place('grace@example.com', '=HYPERLINK("evil")');
        $this->export->choose(new ChooseColumns(['number', 'email', 'billing_name', 'total', 'items']));

        $csv = $this->csv(new OrderQuery(order: 'asc'));

        self::assertSame([
            ['Order', 'Email', 'Billing name', 'Total', 'Items'],
            [(string) $first->number, 'ada@example.com', 'Ada Lovelace', '8', '1 × E-book'],
            [(string) $second->number, 'grace@example.com', "'=HYPERLINK(\"evil\") Lovelace", '8', '1 × E-book'],
        ], $csv, 'a formula a customer typed does not run when the file opens');
        self::assertSame([[(string) $second->number, 'grace@example.com']], array_map(static fn(array $row): array => \array_slice($row, 0, 2), \array_slice($this->csv(new OrderQuery(search: 'grace')), 1)), 'as the list filters');
    }

    public function testAnAddOnsColumnIsListedChosenAndExportedAsJson(): void
    {
        $order = $this->place('ada@example.com', 'Ada');
        $this->shop->extensions->orderColumns[] = self::column('warehouse', 'Warehouse', static fn(OrderSummary $summary): string => "W-{$summary->number}");
        $this->shop->extensions->orderColumns[] = self::column('total', 'Clash', static fn(): string => 'x');
        $this->shop->extensions->orderColumns[] = self::column('broken', 'Broken', static fn(): string => throw new \RuntimeException('down'));

        $columns = $this->export->columns();
        $warehouse = array_values(array_filter($columns, static fn(ColumnView $column): bool => $column->id === 'warehouse'))[0];
        self::assertSame(['Warehouse', true, true], [$warehouse->label, $warehouse->addOn, $warehouse->chosen], 'every column until the shop chooses');
        self::assertCount(1, array_filter($columns, static fn(ColumnView $column): bool => $column->id === 'total'), 'the shop\'s own total, not the add-on\'s');

        $this->export->choose(new ChooseColumns(['number', 'warehouse', 'broken']));
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->export->export(new OrderQuery(), new JsonFile($stream));
        rewind($stream);

        self::assertSame([['number' => (string) $order->number, 'warehouse' => "W-{$order->number}", 'broken' => '']], json_decode((string) stream_get_contents($stream), true));
        self::assertStringContainsString('broken', (string) json_encode($this->shop->logger->records));
    }

    public function testOnlyColumnsThereAreCanBeChosen(): void
    {
        $this->expectException(ExchangeRuleViolation::class);

        $this->export->choose(new ChooseColumns(['number', 'password']));
    }

    /**
     * @return list<list<string>>
     */
    private function csv(OrderQuery $query): array
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->export->export($query, new CsvFile($stream));
        rewind($stream);
        $rows = [];
        $content = substr((string) stream_get_contents($stream), \strlen("\u{FEFF}"));
        foreach (explode("\n", trim($content)) as $line) {
            $rows[] = array_map(strval(...), str_getcsv($line, ',', '"', ''));
        }

        return $rows;
    }

    private function place(string $email, string $firstName): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder($email, new AddressData($firstName, 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));
    }

    /**
     * @param \Closure(OrderSummary): string $value
     */
    private static function column(string $id, string $label, \Closure $value): OrderColumn
    {
        return new readonly class ($id, $label, $value) implements OrderColumn {
            /** @param \Closure(OrderSummary): string $value */
            public function __construct(private string $id, private string $label, private \Closure $value) {}

            public function id(): string
            {
                return $this->id;
            }

            public function label(): string
            {
                return $this->label;
            }

            public function value(OrderSummary $order): string
            {
                return ($this->value)($order);
            }
        };
    }
}
