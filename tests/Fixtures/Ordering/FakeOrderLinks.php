<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Ordering;

use OrillaCart\Ordering\Application\OrderLinks;
use OrillaCart\Ordering\Application\ReceiptLinks;
use OrillaCart\Shared\Security\Signatures;
use Symfony\Component\Clock\MockClock;

final readonly class FakeOrderLinks implements OrderLinks
{
    public ReceiptLinks $receipts;

    public function __construct(?ReceiptLinks $receipts = null)
    {
        $this->receipts = $receipts ?? new ReceiptLinks(new Signatures('test secret'), new MockClock('2026-10-17 12:00:00 UTC'));
    }

    public function receiptUrl(int $orderNumber): string
    {
        return 'https://shop.test/?orillacart_order=' . $this->receipts->issue($orderNumber);
    }

    public function downloadUrl(string $token): string
    {
        return "https://shop.test/?orillacart_download={$token}";
    }
}
