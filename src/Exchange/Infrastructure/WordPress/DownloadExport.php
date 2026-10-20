<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Infrastructure\WordPress;

use OrillaCart\Exchange\Application\CsvFile;
use OrillaCart\Exchange\Application\ExportFile;
use OrillaCart\Exchange\Application\JsonFile;
use OrillaCart\Exchange\Application\OrdersExport;
use OrillaCart\Exchange\Application\ProductsExport;
use OrillaCart\Exchange\Application\SubscribersExport;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Shared\Domain\InvalidFields;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Http\Input\RequestMapper;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Streams an export to the shop manager's browser as it is written, so a file of any size never sits in memory:
 * admin-post.php?action=orillacart_export_orders with the Orders screen's filters and format csv or json, or
 * action=orillacart_export_products for the products file, or action=orillacart_export_subscribers for the
 * newsletter's subscribers, each with the REST nonce the admin screens hold.
 */
#[AsHook('admin_post_orillacart_export_orders', method: 'orders')]
#[AsHook('admin_post_orillacart_export_products', method: 'products')]
#[AsHook('admin_post_orillacart_export_subscribers', method: 'subscribers')]
final readonly class DownloadExport
{
    private const array OWN_PARAMETERS = ['action', 'format', '_wpnonce'];

    public function __construct(
        private OrdersExport $orders,
        private ProductsExport $products,
        private SubscribersExport $subscribers,
        private RequestMapper $input,
    ) {}

    public function orders(): void
    {
        $this->authorize();
        $parameters = wp_unslash($_GET);
        try {
            $query = $this->orderQuery(\is_array($parameters) ? $parameters : []);
        } catch (InvalidFields $e) {
            wp_die(esc_html(implode(' ', array_map(static fn(array $violation): string => "{$violation['field']}: {$violation['message']}", $e->violations))), esc_html__('Export', 'orillacart'), ['response' => 422, 'back_link' => true]);
        } catch (RuleViolation $e) {
            wp_die(esc_html($e->getMessage()), esc_html__('Export', 'orillacart'), ['response' => 422, 'back_link' => true]);
        }
        $this->orders->export($query, $this->file('orders'));
        exit;
    }

    /** The products file, every product and variation: CSV only, as the import reads it. */
    public function products(): void
    {
        $this->authorize();
        $this->products->export($this->file('products', csvOnly: true));
        exit;
    }

    /** The newsletter's subscribers: CSV or JSON. */
    public function subscribers(): void
    {
        $this->authorize();
        $this->subscribers->export($this->file('subscribers'));
        exit;
    }

    /**
     * The Orders screen's filters, as the address carries them beside the export's own parameters.
     *
     * @param array<array-key, mixed> $parameters
     *
     * @throws RuleViolation for filters the list would refuse
     */
    public function orderQuery(array $parameters): OrderQuery
    {
        return $this->input->map(array_filter($parameters, static fn(mixed $value, int|string $name): bool => !\in_array($name, self::OWN_PARAMETERS, true) && $value !== '', ARRAY_FILTER_USE_BOTH), OrderQuery::class, 'csv');
    }

    private function authorize(): void
    {
        $nonce = $_GET['_wpnonce'] ?? '';
        if (!current_user_can(Capability::MANAGE_SHOP) || !\is_string($nonce) || wp_verify_nonce($nonce, 'wp_rest') === false) {
            wp_die(esc_html__('You may not export the shop\'s data.', 'orillacart'), esc_html__('Export', 'orillacart'), ['response' => 403, 'back_link' => true]);
        }
    }

    /** Sends the headers of the file and opens the response for it. */
    private function file(string $name, bool $csvOnly = false): ExportFile
    {
        $json = !$csvOnly && ($_GET['format'] ?? 'csv') === 'json';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: ' . ($json ? 'application/json' : 'text/csv') . '; charset=utf-8');
        header(\sprintf('Content-Disposition: attachment; filename="%s-%s.%s"', $name, gmdate('Y-m-d'), $json ? 'json' : 'csv'));
        header('X-Content-Type-Options: nosniff');
        $stream = fopen('php://output', 'wb');
        if ($stream === false) {
            wp_die(esc_html__('The export could not be written.', 'orillacart'));
        }

        return $json ? new JsonFile($stream) : new CsvFile($stream, headerIds: $csvOnly);
    }
}
