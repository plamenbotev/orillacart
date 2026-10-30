<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

use OrillaCart\Exchange\Application\Dto\ChooseColumns;
use OrillaCart\Exchange\Application\Dto\Column;
use OrillaCart\Exchange\Application\Dto\ColumnView;
use OrillaCart\Exchange\Domain\ExchangeRuleViolation;
use OrillaCart\Exchange\Domain\ExportKind;
use OrillaCart\Extension\Order\OrderColumn;
use OrillaCart\Extension\Order\OrderSummary;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Application\OrderSummaries;
use OrillaCart\Shared\Persistence\LoadedEntities;
use Psr\Log\LoggerInterface;

/**
 * Exports the orders the Orders screen lists, as filtered there, with the columns the shop manager chose: the
 * shop's own and the add-ons'. A batch of orders at a time, each batch read in a few queries and forgotten once
 * written, so a shop of any size exports in little memory; each batch starts after the last order of the one
 * before, so orders placed or deleted meanwhile neither repeat one nor skip one. An add-on's column that fails
 * gives an empty cell, and is logged.
 */
final readonly class OrdersExport
{
    private const int BATCH = 100;

    public function __construct(
        private OrderColumns $columns,
        private ColumnChoice $choice,
        private OrderService $orders,
        private OrderSummaries $summaries,
        private LoadedEntities $entities,
        private LoggerInterface $logger,
    ) {}

    /** @return list<ColumnView> the chosen ones in their order, then the others: the shop's own, then the add-ons' */
    public function columns(): array
    {
        return $this->choice->views(ExportKind::Orders, $this->columns->all());
    }

    /**
     * The columns to export, in this order.
     *
     * @throws ExchangeRuleViolation for a column there is not
     *
     * @return list<ColumnView>
     */
    public function choose(ChooseColumns $command): array
    {
        $this->choice->choose(ExportKind::Orders, $this->columns->all(), $command);

        return $this->columns();
    }

    /**
     * Writes every order the query finds, newest first unless it says otherwise, whatever its page.
     *
     * @param (\Closure(): void)|null $between run after each batch, to free what else the batch kept
     */
    public function export(OrderQuery $query, ExportFile $file, ?\Closure $between = null): void
    {
        $columns = $this->choice->chosen(ExportKind::Orders, $this->columns->all());
        $file->start(array_map(ColumnChoice::id(...), $columns), array_map($this->choice->label(...), $columns));
        $this->summaries->each($this->orders->search($query, forShop: true), self::BATCH, function (array $orders) use ($file, $columns, $between): void {
            foreach ($orders as $order) {
                $file->row(array_map(fn(Column|OrderColumn $column): string => $this->value($column, $order), $columns));
            }
            // Nothing of the export is saved: what the batch loaded can go.
            $this->entities->forget();
            if ($between !== null) {
                $between();
            }
        });
        $file->finish();
    }

    /** @param Column<OrderSummary>|OrderColumn $column */
    private function value(Column|OrderColumn $column, OrderSummary $order): string
    {
        if ($column instanceof Column) {
            return ($column->value)($order);
        }
        try {
            return $column->value($order);
        } catch (\Throwable $e) {
            $this->logger->error('The order column {id} failed for order {order}: {message}', ['id' => $column->id(), 'order' => $order->number, 'message' => $e->getMessage(), 'exception' => $e]);

            return '';
        }
    }
}
