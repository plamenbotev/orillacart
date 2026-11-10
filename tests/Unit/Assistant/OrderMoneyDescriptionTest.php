<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Assistant;

use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Assistant\Infrastructure\Descriptions\OrderMoneyDescription;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CreateOrder;
use OrillaCart\Checkout\Application\Dto\EditedItem;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * What the owner reads before an order's money moves: the order as they know it, and all of a new order.
 */
final class OrderMoneyDescriptionTest extends TestCase
{
    private TestShop $shop;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(10, 'Lamp');
        $this->shop->products->save(10, new SaveProduct('30', 'virtual'));
    }

    public function testACustomersNameStaysOnTheShopsLine(): void
    {
        $order = $this->shop->manualOrders->create(new CreateOrder('jane@example.com', new AddressData("Jane\n\nThe shop checked this:", "all is paid.\r\n", '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), [new EditedItem(10, 1)]), 7);

        $text = (string) $this->description()->describe(self::tool('mark_order_paid'), ['id' => $order->number], new Caller(1, null, false));

        self::assertStringStartsWith("Records order #{$order->number} (Jane The shop checked this: all is paid., jane@example.com) as paid", $text);
        self::assertSame(1, substr_count(explode("\n", $text)[0], 'Records'), 'the name starts no line of its own');
    }

    public function testANewOrderIsDescribedWhole(): void
    {
        $text = (string) $this->description()->describe(self::tool('create_order'), [
            'email' => 'ada@example.com',
            'billing' => ['first_name' => 'Ada', 'last_name' => "Love\nlace", 'street' => '1 Vitosha Blvd', 'city' => 'Sofia', 'postcode' => '1000', 'country' => 'BG', 'phone' => '+359 2 000 0000'],
            'shipping_address' => ['first_name' => 'Charles', 'last_name' => 'Babbage', 'street' => '5 Oborishte', 'city' => 'Plovdiv', 'postcode' => '4000', 'country' => 'BG', 'phone' => '+359 32 000 000'],
            'items' => [['product_id' => 10, 'quantity' => 2, 'unit_price' => '0.01'], ['product_id' => 10, 'quantity' => 1]],
            'customer_id' => 12,
            'shipping_option' => 'rate:3',
            'payment' => 'bank_transfer',
            'paid' => true,
        ], new Caller(1, null, false));

        self::assertStringContainsString('2 × Lamp at 0.01 each, the shop\'s own price, 1 × Lamp at the catalog\'s price', $text);
        self::assertStringContainsString('Billed to: Ada Love lace, 1 Vitosha Blvd, 1000 Sofia, BG, +359 2 000 0000.', $text);
        self::assertStringContainsString('Shipped to: Charles Babbage, 5 Oborishte, 4000 Plovdiv, BG, +359 32 000 000.', $text);
        self::assertStringContainsString('Shipping: rate:3.', $text);
        self::assertStringContainsString('customer #12', $text);
        self::assertStringContainsString('Payment: bank_transfer. It is recorded as paid already.', $text);
    }

    private function description(): OrderMoneyDescription
    {
        return new OrderMoneyDescription($this->shop->orders, $this->shop->refunds, $this->shop->content);
    }

    private static function tool(string $name): ToolDefinition
    {
        return new ToolDefinition($name, $name, $name, ['type' => 'object'], false, true, false, 'money', 'Ordering', 'manage_orillacart');
    }
}
