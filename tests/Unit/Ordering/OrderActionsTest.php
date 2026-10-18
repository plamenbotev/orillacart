<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Ordering;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Extension\Order\OrderAction;
use OrillaCart\Extension\Order\OrderSummary;
use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\OrderActionView;
use OrillaCart\Ordering\Application\OrderActions;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class OrderActionsTest extends TestCase
{
    private TestShop $shop;
    private OrderActions $actions;
    private int $number;
    /** @var list<int> orders the invoice action ran for */
    private array $invoiced = [];
    private bool $down = false;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);
        $this->number = $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'))->number;

        $this->shop->extensions->actions[] = new readonly class ($this) implements OrderAction {
            public function __construct(private OrderActionsTest $test) {}

            public function id(): string
            {
                return 'invoice';
            }

            public function label(): string
            {
                return 'Send the invoice';
            }

            public function isAvailable(OrderSummary $order): bool
            {
                return $order->status !== 'cancelled';
            }

            public function run(OrderSummary $order): string
            {
                return $this->test->invoice($order);
            }
        };
        $this->actions = new OrderActions($this->shop->extensions, $this->shop->orders, $this->shop->logger);
    }

    public function invoice(OrderSummary $order): string
    {
        if ($this->down) {
            throw new ProviderUnavailable('Invoices', 'The invoicing service did not answer.');
        }
        $this->invoiced[] = $order->number;

        return "Invoice {$order->number} sent to {$order->email}.";
    }

    public function testTheOrderOffersTheActionsThatApplyAndRunsOne(): void
    {
        self::assertEquals([new OrderActionView('invoice', 'Send the invoice')], $this->actions->available($this->number));

        $result = $this->actions->run($this->number, 'invoice', 1);
        self::assertSame("Invoice {$this->number} sent to ada@example.com.", $result->message);
        self::assertSame("Send the invoice: Invoice {$this->number} sent to ada@example.com.", $result->order->notes[\count($result->order->notes) - 1]->text);
        self::assertSame([$this->number], $this->invoiced);
    }

    public function testAFailureIsShownAndNothingIsRecorded(): void
    {
        $this->down = true;
        $notes = \count($this->shop->orders->order($this->number)->notes);
        try {
            $this->actions->run($this->number, 'invoice', 1);
            self::fail('the service is down');
        } catch (OrderRuleViolation $e) {
            self::assertSame(['Invoices: The invoicing service did not answer.', 'orillacart_order_action_failed'], [$e->getMessage(), $e->errorCode]);
        }
        self::assertCount($notes, $this->shop->orders->order($this->number)->notes);
    }

    public function testAnActionTheOrderDoesNotOfferIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->expectExceptionMessage('This order offers no such action.');
        $this->actions->run($this->number, 'export', 1);
    }
}
