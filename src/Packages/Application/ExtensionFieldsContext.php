<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Customers\Application\Dto\CustomerView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Shared\View\TemplateContextSource;

/**
 * The extensions' fields on the record a template shows, under `ext.<extension>.product`, `.order` or
 * `.customer`: on the product page `ext.lead_times.product.lead_time`, in an order's emails and invoice
 * `ext.courier.order.courier_ref`. A template whose context has none of the shop's records gets nothing.
 */
final readonly class ExtensionFieldsContext implements TemplateContextSource
{
    public function __construct(private ExtensionFields $fields) {}

    public function add(string $template, array $context): array
    {
        $added = [];
        $offer = $context['offer'] ?? null;
        if ($offer instanceof ProductOffer) {
            $this->put($added, 'product', $offer->id);
        }
        $order = $context['order'] ?? null;
        if ($order instanceof OrderView) {
            $this->put($added, 'order', $order->number);
        }
        $customer = $context['customer'] ?? null;
        if ($customer instanceof CustomerView) {
            $this->put($added, 'customer', $customer->id);
        }

        return $added;
    }

    /**
     * @param array<string, array<string, mixed>> $added
     */
    private function put(array &$added, string $owner, int $id): void
    {
        foreach ($this->fields->of($owner, $id) as $extension => $values) {
            $added[$extension][$owner] = $values;
        }
    }
}
