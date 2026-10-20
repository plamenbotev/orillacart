<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

use OrillaCart\Exchange\Application\Dto\ChooseColumns;
use OrillaCart\Exchange\Application\Dto\Column;
use OrillaCart\Exchange\Application\Dto\ColumnView;
use OrillaCart\Exchange\Domain\ExchangeRuleViolation;
use OrillaCart\Exchange\Domain\ExportChoices;
use OrillaCart\Exchange\Domain\ExportKind;
use OrillaCart\Extension\Catalog\ImportsProductColumn;
use OrillaCart\Extension\Catalog\ProductColumn;
use OrillaCart\Extension\Order\OrderColumn;

/**
 * Which columns an export writes, and in what order, as the shop manager chose: the shop's own (Column) and the
 * add-ons' alike. Every column while the shop never chose; a chosen column that is gone, as with an add-on
 * turned off, drops out.
 */
final readonly class ColumnChoice
{
    public function __construct(
        private ExportChoices $choices,
        private ColumnNames $names,
    ) {}

    /**
     * @param array<string, Column<covariant mixed>|OrderColumn|ProductColumn> $all by id
     *
     * @return list<ColumnView> the chosen ones in their order, then the others
     */
    public function views(ExportKind $kind, array $all): array
    {
        $chosen = $this->chosen($kind, $all);
        $others = array_diff_key($all, array_flip(array_map(self::id(...), $chosen)));

        return [
            ...array_map(fn(Column|OrderColumn|ProductColumn $column): ColumnView => $this->view($column, true), $chosen),
            ...array_values(array_map(fn(Column|OrderColumn|ProductColumn $column): ColumnView => $this->view($column, false), $others)),
        ];
    }

    /**
     * @param array<string, Column<covariant mixed>|OrderColumn|ProductColumn> $all
     *
     * @throws ExchangeRuleViolation for a column there is not
     */
    public function choose(ExportKind $kind, array $all, ChooseColumns $command): void
    {
        foreach ($command->columns as $id) {
            if (!isset($all[$id])) {
                throw new ExchangeRuleViolation(\sprintf('There is no %s column "%s".', $kind === ExportKind::Orders ? 'order' : 'product', $id), 'orillacart_unknown_column');
            }
        }
        $this->choices->choose($kind, array_values(array_unique($command->columns)));
    }

    /**
     * @template C of Column<covariant mixed>|OrderColumn|ProductColumn
     *
     * @param array<string, C> $all
     *
     * @return list<C>
     */
    public function chosen(ExportKind $kind, array $all): array
    {
        $chosen = $this->choices->chosen($kind);
        if ($chosen === null) {
            return array_values($all);
        }
        $found = [];
        foreach ($chosen as $id) {
            if (isset($all[$id])) {
                $found[] = $all[$id];
            }
        }

        return $found;
    }

    /** @param Column<covariant mixed>|OrderColumn|ProductColumn $column */
    public static function id(Column|OrderColumn|ProductColumn $column): string
    {
        return $column instanceof Column ? $column->id : $column->id();
    }

    /** @param Column<covariant mixed>|OrderColumn|ProductColumn $column */
    public function label(Column|OrderColumn|ProductColumn $column): string
    {
        return $column instanceof Column ? $this->names->of($column->id, $column->label) : $column->label();
    }

    /** @param Column<covariant mixed>|OrderColumn|ProductColumn $column */
    private function view(Column|OrderColumn|ProductColumn $column, bool $chosen): ColumnView
    {
        return new ColumnView(
            self::id($column),
            $this->label($column),
            !$column instanceof Column,
            $chosen,
            $column instanceof Column ? $column->importable : $column instanceof ImportsProductColumn,
        );
    }
}
