<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Infrastructure\WordPress;

use OrillaCart\Exchange\Application\CsvFile;
use OrillaCart\Exchange\Application\ExportFile;
use OrillaCart\Exchange\Application\JsonFile;
use OrillaCart\Exchange\Application\OrdersExport;
use OrillaCart\Exchange\Application\ProductsExport;
use OrillaCart\Exchange\Application\SubscribersExport;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\WordPress\CliCommand;

/**
 * Exports the shop's orders, products or newsletter subscribers, as the Export screens do, to a file or the
 * standard output: for backups, accountants and scripts.
 */
final readonly class ExportCommand implements CliCommand
{
    public function __construct(
        private OrdersExport $orders,
        private ProductsExport $products,
        private SubscribersExport $subscribers,
        private DownloadExport $download,
    ) {}

    public static function name(): string
    {
        return 'orillacart export';
    }

    /**
     * Writes an export, with the columns the Export screen chose, as it is read: an export of any size never sits
     * in memory.
     *
     * ## OPTIONS
     *
     * <what>
     * : orders, products or subscribers.
     *
     * [--format=<format>]
     * : csv or json; products are CSV only, as the import reads them.
     * ---
     * default: csv
     * ---
     *
     * [--file=<path>]
     * : Where to write it; the standard output when left out.
     *
     * [--<field>=<value>]
     * : For orders, the Orders screen's filters: --status=processing, --placed_from=2026-10-01,
     * --placed_before=2026-11-01, --search=ada@example.com, --payment=paypal.
     *
     * ## EXAMPLES
     *
     *     wp orillacart export orders --placed_from=2026-10-01 --placed_before=2026-11-01 --file=october.csv
     *     wp orillacart export products > products.csv
     *     wp orillacart export subscribers --format=json
     *
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function __invoke(array $args, array $options): void
    {
        $what = $args[0] ?? '';
        $format = $options['format'] ?? 'csv';
        if (!\in_array($what, ['orders', 'products', 'subscribers'], true)) {
            \WP_CLI::error('Export orders, products or subscribers.');
        }
        if (!\in_array($format, ['csv', 'json'], true) || ($what === 'products' && $format === 'json')) {
            \WP_CLI::error($what === 'products' ? 'Products export as CSV only.' : 'The format is csv or json.');
        }
        $filters = array_diff_key($options, array_flip(['format', 'file']));
        $path = $options['file'] ?? 'php://output';
        $stream = fopen($path, 'wb');
        if ($stream === false) {
            \WP_CLI::error("Cannot write {$path}.");
        }
        try {
            $file = self::file($stream, $format, $what === 'products');
            LongExport::start();
            match ($what) {
                'orders' => $this->orders->export($this->download->orderQuery($filters), $file, LongExport::between(...)),
                'products' => $this->products->export($file, LongExport::between(...)),
                default => $this->subscribers->export($file),
            };
        } catch (HttpException $e) {
            \WP_CLI::error(DownloadExport::problems($e));
        } catch (RuleViolation $e) {
            \WP_CLI::error($e->getMessage());
        } finally {
            fclose($stream);
        }
        if (isset($options['file'])) {
            \WP_CLI::success("Exported {$what} to {$options['file']}.");
        }
    }

    /**
     * @param resource $stream
     */
    private static function file($stream, string $format, bool $headerIds): ExportFile
    {
        return $format === 'json' ? new JsonFile($stream) : new CsvFile($stream, headerIds: $headerIds);
    }
}
