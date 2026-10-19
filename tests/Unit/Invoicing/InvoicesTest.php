<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Invoicing;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Inventory\Application\Dto\SaveStockroom;
use OrillaCart\Inventory\Application\Dto\SetStockLevel;
use OrillaCart\Inventory\Application\Dto\SetStockLevels;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Invoicing\Application\Dto\InvoiceView;
use OrillaCart\Invoicing\Application\Dto\UpdateInvoiceSettings;
use OrillaCart\Invoicing\Domain\InvoiceNotFound;
use OrillaCart\Notifications\Application\Dto\SendOrderEmail;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Ordering\Application\Dto\RefundLine;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class InvoicesTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop('2026-12-31 20:00:00 UTC');
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $room = $this->shop->stockrooms->create(new SaveStockroom('Sofia'))->id;
        $this->shop->stock->setLevels(StockItemRef::product(20), new SetStockLevels([new SetStockLevel($room, 10)]));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->shop->invoices->updateSettings(new UpdateInvoiceSettings('Lamps Ltd', "1 Vitosha Blvd\nSofia", 'BG123456789'));
    }

    public function testAnOrderGetsTheNextNumberOnceItIsPaidAndNotBefore(): void
    {
        $first = $this->place();
        $second = $this->place();
        self::assertSame([], $this->shop->invoices->ofOrder($first->number), 'not paid yet');

        $this->shop->orders->markPaidByShop($second->number, '', 7);
        $this->shop->orders->markPaidByShop($first->number, '', 7);
        $this->shop->orders->changeStatus($first->number, new ChangeOrderStatus('shipped'), 7);

        // 22:00 in Sofia on the last day of the year.
        self::assertSame(['INV-2026-00002'], self::numbers($this->shop->invoices->ofOrder($first->number)), 'once, in the order it was paid');
        self::assertSame(['INV-2026-00001'], self::numbers($this->shop->invoices->ofOrder($second->number)));

        $this->shop->clock->modify('+2 hours');
        $third = $this->place();
        $this->shop->orders->markPaidByShop($third->number, '', 7);
        self::assertSame(['INV-2027-00001'], self::numbers($this->shop->invoices->ofOrder($third->number)), 'midnight in Sofia restarts the year');
    }

    public function testMoneyGivenBackGetsACreditNoteOfItsOwnSeries(): void
    {
        $order = $this->place(2);
        $this->shop->orders->markPaidByShop($order->number, '', 7);

        $this->shop->refunds->refund($order->number, new MakeRefund('8', [new RefundLine(0, 1)]), 7);

        $documents = $this->shop->invoices->ofOrder($order->number);
        self::assertSame(['INV-2026-00001', 'CN-2026-00001'], self::numbers($documents));
        $credit = $this->shop->invoices->document('CN-2026-00001', $this->shop->receipts->issue($order->number));
        self::assertSame(['credit_note', '8', 'INV-2026-00001', [['line' => 0, 'quantity' => 1]]], [$credit->invoice->kind, $credit->invoice->amount, $credit->corrects, $credit->lines]);
        self::assertSame('BG123456789', $credit->seller['vat_number']);
    }

    public function testADocumentKeepsTheSellerAsItWasAndOpensOnlyWithAWorkingLinkToItsOrder(): void
    {
        $order = $this->place();
        $this->shop->orders->markPaidByShop($order->number, '', 7);
        $this->shop->invoices->updateSettings(new UpdateInvoiceSettings('Lamps Holding Ltd'));
        $key = $this->shop->receipts->issue($order->number);

        $document = $this->shop->invoices->document('INV-2026-00001', $key);
        self::assertSame(['Lamps Ltd', '8'], [$document->seller['legal_name'], $document->invoice->amount]);
        self::assertSame("https://shop.test/?orillacart_invoice=INV-2026-00001&receipt={$key}", $document->invoice->url);

        $other = $this->shop->receipts->issue($order->number + 1);
        $this->shop->clock->modify('+90 days');
        foreach (['1-1-' . str_repeat('0', 32), $other, $key] as $refused) {
            try {
                $this->shop->invoices->document('INV-2026-00001', $refused);
                self::fail("{$refused} opens no other order's invoice, and none once expired");
            } catch (InvoiceNotFound) {
            }
        }
    }

    public function testThePrintablePageShowsTheSellerTheBuyerAndTheTotal(): void
    {
        $order = $this->place(2);
        $this->shop->orders->markPaidByShop($order->number, '', 7);
        $key = $this->shop->receipts->issue($order->number);

        $html = $this->shop->templates->render('invoices/document.html.twig', ['document' => $this->shop->invoices->document('INV-2026-00001', $key), 'locale' => 'en-US']);

        self::assertStringContainsString('<title>Invoice INV-2026-00001</title>', $html);
        self::assertStringContainsString('Lamps Ltd', $html);
        self::assertStringContainsString('BG123456789', $html);
        self::assertStringContainsString('Ada Lovelace', $html);
        self::assertStringContainsString('16.00 EUR', $html);
    }

    public function testTheCustomersEmailLinksTheInvoice(): void
    {
        $order = $this->place();
        $this->shop->orders->markPaidByShop($order->number, '', 7);

        self::assertTrue($this->shop->emails->resend($order->number, new SendOrderEmail('order_processing')));

        $emails = $this->shop->mail->emails;
        self::assertStringContainsString('Invoice INV-2026-00001', $emails[\count($emails) - 1]->html);
    }

    public function testNumberingThatRunsOnAcrossYears(): void
    {
        $this->shop->invoices->updateSettings(new UpdateInvoiceSettings(invoicePrefix: 'F', yearly: false));
        $order = $this->place();
        $this->shop->orders->markPaidByShop($order->number, '', 7);

        self::assertSame(['F00001'], self::numbers($this->shop->invoices->ofOrder($order->number)));
    }

    public function testInvoicesAndCreditNotesNeedPrefixesOfTheirOwn(): void
    {
        $this->expectException(RuleViolation::class);
        $this->shop->invoices->updateSettings(new UpdateInvoiceSettings(invoicePrefix: 'X-', creditNotePrefix: 'X-'));
    }

    /**
     * @param list<InvoiceView> $invoices
     *
     * @return list<string>
     */
    private static function numbers(array $invoices): array
    {
        return array_map(static fn(InvoiceView $invoice): string => $invoice->number, $invoices);
    }

    private function place(int $quantity = 1): PlacedOrderView
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20, quantity: $quantity))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));
    }
}
