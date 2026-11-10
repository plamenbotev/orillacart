<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Invoicing;

use OrillaCart\Invoicing\Application\InvoiceLinks;

final class FakeInvoiceLinks implements InvoiceLinks
{
    public function documentUrl(string $number, string $key): string
    {
        return "https://shop.test/?orillacart_invoice={$number}&key={$key}";
    }

    public function xmlUrl(string $number, string $key): string
    {
        return "https://shop.test/?orillacart_invoice={$number}&key={$key}&format=xml";
    }
}
