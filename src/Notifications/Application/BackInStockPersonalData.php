<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Customers\Application\PersonalDataSource;
use OrillaCart\Notifications\Domain\BackInStockRequests;

/** The products a person asked to hear of when they are back in stock. */
final readonly class BackInStockPersonalData implements PersonalDataSource
{
    public function __construct(private BackInStockRequests $requests) {}

    public function personalData(string $email, ?int $customerId): array
    {
        $items = [];
        foreach ($this->requests->ofEmail(strtolower(trim($email))) as $index => $request) {
            $items[] = ['group' => 'orillacart-back-in-stock', 'label' => 'Back in stock requests', 'id' => 'orillacart-back-in-stock-' . $index, 'data' => [
                ['name' => 'Product', 'value' => '#' . $request->productId()],
                ['name' => 'Asked', 'value' => $request->at()->format(\DATE_ATOM)],
            ]];
        }

        return $items;
    }
}
