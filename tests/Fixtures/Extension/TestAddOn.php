<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Extension;

use OrillaCart\Extension\Cart\CartRefusal;
use OrillaCart\Extension\Cart\CartRule;
use OrillaCart\Extension\Cart\CheckedCart;
use OrillaCart\Extension\Cart\CheckedLine;
use OrillaCart\Extension\Catalog\FindsProducts;
use OrillaCart\Extension\Catalog\ProductFilter;
use OrillaCart\Extension\Checkout\CheckoutField;
use OrillaCart\Extension\Order\FindsOrders;
use OrillaCart\Extension\Order\OrderAction;
use OrillaCart\Extension\Order\OrderColumn;
use OrillaCart\Extension\Order\OrderFilter;
use OrillaCart\Extension\Order\OrderSummary;
use OrillaCart\Extension\Pricing\PriceAdjuster;
use OrillaCart\Extension\Pricing\PriceQuery;
use OrillaCart\Extension\Registry;

/**
 * An add-on, registered on orillacart_extensions as another plugin would be: a gateway and a shipping method of
 * its own, a checkout field, a price adjuster (off until a test
 * sets a price), a cart rule (at most 50 of an item), an order action (which marks the order exported) and an order column,
 * an order filter and search, and a product filter and search.
 */
final class TestAddOn
{
    /** The price the adjuster gives every product while set. */
    public static ?string $price = null;
    /** @var array<string, list<int>> the products the barcode search finds, by barcode */
    public static array $barcodes = [];
    /** The registry, for tests marking products as the add-on would. */
    public static ?Registry $registry = null;

    public static function register(Registry $registry): void
    {
        $registry->paymentGateway(new TestPayGateway());
        $registry->shippingMethod(new TestShipMethod());
        $registry->checkoutField(new CheckoutField('gift_note', 'Gift note', CheckoutField::TEXTAREA, CheckoutField::ORDER, help: 'We print it on a card.'));
        $registry->priceAdjuster(new class implements PriceAdjuster {
            public function id(): string
            {
                return 'test_price';
            }

            public function adjust(PriceQuery $query): ?string
            {
                return TestAddOn::$price;
            }
        });
        $registry->cartRule(new class implements CartRule {
            public function id(): string
            {
                return 'test_limit';
            }

            public function check(CheckedCart $cart): array
            {
                return array_values(array_map(
                    static fn(CheckedLine $line): CartRefusal => CartRefusal::line($line->id, 'At most 50 of an item.'),
                    array_filter($cart->lines, static fn(CheckedLine $line): bool => $line->quantity > 50),
                ));
            }
        });
        $registry->orderAction(new class ($registry) implements OrderAction {
            public function __construct(private Registry $registry) {}

            public function id(): string
            {
                return 'test_export';
            }

            public function label(): string
            {
                return 'Export';
            }

            public function isAvailable(OrderSummary $order): bool
            {
                return $order->status !== 'cancelled';
            }

            public function run(OrderSummary $order): string
            {
                // Reads the order again, as an add-on reacting to orillacart_order_placed would.
                $again = $this->registry->orders()->find($order->number);
                $this->registry->orders()->mark($order->number, 'test_exported');

                return \sprintf('Exported #%d for %s.', $order->number, $again?->email);
            }
        });
        if ($registry->version() >= 4) {
            $registry->orderColumn(new class implements OrderColumn {
                public function id(): string
                {
                    return 'test_warehouse';
                }

                public function label(): string
                {
                    return 'Warehouse';
                }

                public function value(OrderSummary $order): string
                {
                    return 'W-' . $order->number;
                }
            });
        }
        if ($registry->version() >= 8) {
            self::$registry = $registry;
            $registry->orderFilter(new class implements OrderFilter {
                public function id(): string
                {
                    return 'test_exported';
                }

                public function label(): string
                {
                    return 'Exported';
                }
            });
            // The warehouse's reference of an order: "EXP-" and its number.
            $registry->orderSearch(new class implements FindsOrders {
                public function id(): string
                {
                    return 'test_references';
                }

                public function findOrders(string $text): array
                {
                    return preg_match('/^EXP-(\d+)$/', $text, $found) === 1 ? [(int) $found[1]] : [];
                }
            });
            $registry->productFilter(new class implements ProductFilter {
                public function id(): string
                {
                    return 'test_marketplace';
                }

                public function label(): string
                {
                    return 'On the marketplace';
                }
            });
            $registry->productSearch(new class implements FindsProducts {
                public function id(): string
                {
                    return 'test_barcodes';
                }

                public function findProducts(string $text): array
                {
                    return TestAddOn::$barcodes[$text] ?? [];
                }
            });
        }
    }
}
