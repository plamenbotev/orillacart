<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Infrastructure\WordPress;

use OrillaCart\Exchange\Domain\ExportChoices;
use OrillaCart\Exchange\Domain\ExportKind;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;

/**
 * Keeps the chosen export columns in one WordPress option, by kind.
 */
final readonly class OptionExportChoices implements ExportChoices, LifecycleListener, RemovesShopData
{
    public const string OPTION = 'orillacart_export_columns';

    public function chosen(ExportKind $kind): ?array
    {
        $stored = get_option(self::OPTION);
        $columns = \is_array($stored) ? ($stored[$kind->value] ?? null) : null;

        return \is_array($columns) ? array_values(array_filter($columns, \is_string(...))) : null;
    }

    public function choose(ExportKind $kind, array $columns): void
    {
        $stored = get_option(self::OPTION);
        $stored = \is_array($stored) ? $stored : [];
        $stored[$kind->value] = $columns;
        update_option(self::OPTION, $stored, false);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
