<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

use OrillaCart\Exchange\Application\Dto\ChooseColumns;
use OrillaCart\Exchange\Application\Dto\Column;
use OrillaCart\Exchange\Application\Dto\ColumnView;
use OrillaCart\Exchange\Domain\ExchangeRuleViolation;
use OrillaCart\Exchange\Domain\ExportChoices;
use OrillaCart\Exchange\Domain\ExportKind;
use OrillaCart\Extension\Order\OrderColumn;
use OrillaCart\Extension\Order\OrderSummary;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Application\OrderSummaries;
use Psr\Log\LoggerInterface;

/**
 * Exports the orders the Orders screen lists, as filtered there, with the columns the shop manager chose: the
 * shop's own and the add-ons'. A batch of orders at a time, so a shop of any size exports in little memory. An
 * add-on's column that fails gives an empty cell, and is logged.
 */
final readonly class OrdersExport
{
    private const int BATCH = 100;

    public function __construct(
        private OrderColumns $columns,
        private ExportChoices $choices,
        private OrderService $orders,
        private OrderSummaries $summaries,
        private ColumnNames $names,
        private LoggerInterface $logger,
    ) {}

    /** @return list<ColumnView> the chosen ones in their order, then the others: the shop's own, then the add-ons' */
    public function columns(): array
    {
        $chosen = $this->chosen();
        $others = array_diff_key($this->columns->all(), array_flip(array_map(self::id(...), $chosen)));

        return [
            ...array_map(fn(Column|OrderColumn $column): ColumnView => new ColumnView(self::id($column), $this->label($column), $column instanceof OrderColumn, true), $chosen),
            ...array_values(array_map(fn(Column|OrderColumn $column): ColumnView => new ColumnView(self::id($column), $this->label($column), $column instanceof OrderColumn, false), $others)),
        ];
    }

    /**
     * The columns to export, in the order chosen; every one while the shop never chose. A chosen column that is
     * gone, as with an add-on turned off, drops out.
     *
     * @return list<Column<OrderSummary>|OrderColumn>
     */
    private function chosen(): array
    {
        $all = $this->columns->all();
        $chosen = $this->choices->chosen(ExportKind::Orders);
        if ($chosen === null) {
            return array_values($all);
        }

        return array_values(array_filter(array_map(static fn(string $id): Column|OrderColumn|null => $all[$id] ?? null, $chosen)));
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
        $all = $this->columns->all();
        foreach ($command->columns as $id) {
            if (!isset($all[$id])) {
                throw new ExchangeRuleViolation(\sprintf('There is no order column "%s".', $id), 'orillacart_unknown_column');
            }
        }
        $this->choices->choose(ExportKind::Orders, array_values(array_unique($command->columns)));

        return $this->columns();
    }

    /**
     * Writes every order the query finds, newest first unless it says otherwise, whatever its page.
     */
    public function export(OrderQuery $query, ExportFile $file): void
    {
        $columns = $this->chosen();
        $file->start(array_map(self::id(...), $columns), array_map($this->label(...), $columns));
        $page = 1;
        do {
            $found = $this->orders->orders(new OrderQuery(
                $query->status,
                $query->search,
                $query->customerId,
                $page,
                self::BATCH,
                $query->attention,
                $query->placedFrom,
                $query->placedBefore,
                $query->payment,
                $query->minTotal,
                $query->maxTotal,
                $query->placedBy,
                $query->orderBy,
                $query->order,
            ), forShop: true);
            foreach ($found->orders as $listed) {
                $order = $this->summaries->find($listed->number);
                if ($order !== null) {
                    $file->row(array_map(fn(Column|OrderColumn $column): string => $this->value($column, $order), $columns));
                }
            }
            ++$page;
        } while ($page <= $found->pages);
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

    /** @param Column<OrderSummary>|OrderColumn $column */
    private static function id(Column|OrderColumn $column): string
    {
        return $column instanceof Column ? $column->id : $column->id();
    }

    /** @param Column<OrderSummary>|OrderColumn $column */
    private function label(Column|OrderColumn $column): string
    {
        return $column instanceof Column ? $this->names->of($column->id, $column->label) : $column->label();
    }
}
