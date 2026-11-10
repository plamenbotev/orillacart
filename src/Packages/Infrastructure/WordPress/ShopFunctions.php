<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress;

use OrillaCart\Extension\Registry;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\HostNamespace;
use OrillaCart\Scripting\Domain\ScriptError;
use OrillaCart\Scripting\Domain\ScriptFailure;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * `shop.orders` and `shop.products` as the add-on API lets add-ons reach them: an order by number, and the marks
 * the lists filter by; products and customers read, email sent, orders placed (design 61). The registry is reached
 * lazily: it is what registers the scripted extensions.
 */
final readonly class ShopFunctions implements HostNamespace
{
    public function __construct(
        #[AutowireServiceClosure(Registry::class)]
        private \Closure $registry,
    ) {}

    public function functions(): array
    {
        return [
            new HostFunction('shop.orders.find', fn(Context $c, mixed $number): ?array => ($order = $this->registry()->orders()->find((int) Values::text($number))) === null ? null : Values::order($order), 1, 1, 'shop.orders.find(number): the order, or null', 'orders:read', HostFunction::QUERY),
            new HostFunction('shop.orders.mark', fn(Context $c, mixed $number, mixed $mark): bool => $this->mark(fn() => $this->registry()->orders()->mark((int) Values::text($number), Values::text($mark))), 2, 2, 'shop.orders.mark(number, filter_id): puts the order under the extension\'s order filter; false when there is no such order', 'orders:write'),
            new HostFunction('shop.orders.unmark', function (Context $c, mixed $number, mixed $mark): null {
                $this->mark(function () use ($number, $mark): bool {
                    $this->registry()->orders()->unmark((int) Values::text($number), Values::text($mark));

                    return true;
                });

                return null;
            }, 2, 2, 'shop.orders.unmark(number, filter_id): takes the order out of the filter', 'orders:write'),
            new HostFunction('shop.orders.is_marked', fn(Context $c, mixed $number, mixed $mark): bool => $this->mark(fn() => $this->registry()->orders()->isMarked((int) Values::text($number), Values::text($mark))), 2, 2, 'shop.orders.is_marked(number, filter_id): whether the order is under the filter', 'orders:read'),
            new HostFunction('shop.products.mark', fn(Context $c, mixed $productId, mixed $mark): bool => $this->mark(fn() => $this->registry()->products()->mark((int) Values::text($productId), Values::text($mark))), 2, 2, 'shop.products.mark(product_id, filter_id): puts the product under the extension\'s product filter', 'products:write'),
            new HostFunction('shop.products.unmark', function (Context $c, mixed $productId, mixed $mark): null {
                $this->mark(function () use ($productId, $mark): bool {
                    $this->registry()->products()->unmark((int) Values::text($productId), Values::text($mark));

                    return true;
                });

                return null;
            }, 2, 2, 'shop.products.unmark(product_id, filter_id): takes the product out of the filter', 'products:write'),
            new HostFunction('shop.products.find', fn(Context $c, mixed $productId): ?array => ($product = $this->registry()->products()->find((int) Values::text($productId))) === null ? null : Values::product($product), 1, 1, 'shop.products.find(product_id): the product as shoppers see it now ({ id, name, type, sku, price, regular_price, for_sale, available, on_backorder, variations[{ id, label, sku, price, regular_price, for_sale, available }] }), or null', 'products:read', HostFunction::QUERY),
            new HostFunction('shop.customers.find', fn(Context $c, mixed $customerId): ?array => ($customer = $this->registry()->customers()->find((int) Values::text($customerId))) === null ? null : Values::customer($customer), 1, 1, 'shop.customers.find(customer_id): the customer ({ id, email, first_name, last_name, registered_at, billing, shipping_address, group_ids, orders }), or null', 'customers:read', HostFunction::QUERY),
            new HostFunction('shop.email.send', fn(Context $c, mixed $to, mixed $subject, mixed $body, mixed $replyTo = null): bool => $this->emails(fn(): bool => $this->registry()->emails()->send(Values::text($to), Values::text($subject), Values::text($body), Values::optionalText($replyTo))), 3, 4, 'shop.email.send(to, subject, html, reply_to?): an email in the shop\'s layout, from its address; true when the mail system took it', 'email:send', HostFunction::EMAIL, static fn(Context $c, mixed $to, mixed $subject, mixed $body, mixed $replyTo = null): bool => true),
            new HostFunction('shop.orders.create', fn(Context $c, mixed $order): array => Values::order($this->placed(fn() => $this->registry()->orderCreation()->create(Values::orderRequest(\is_array($order) ? $order : [])))), 1, 1, 'shop.orders.create({ email, customer_id, billing, shipping_address, items: [{ product_id, quantity, option_ids, unit_price }], shipping_option, payment, paid, note, fields }): places the order as the shop does by hand, and answers the order map', 'orders:create', HostFunction::QUERY, fn(Context $c, mixed $order): array => Values::order($this->placed(fn() => $this->registry()->orderCreation()->create(Values::orderRequest(['test' => true, 'email_customer' => false] + (\is_array($order) ? $order : [])))))),
            new HostFunction('shop.products.is_marked', fn(Context $c, mixed $productId, mixed $mark): bool => $this->mark(fn() => $this->registry()->products()->isMarked((int) Values::text($productId), Values::text($mark))), 2, 2, 'shop.products.is_marked(product_id, filter_id): whether the product is under the filter', 'products:read'),
        ];
    }

    private function registry(): Registry
    {
        $registry = ($this->registry)();
        \assert($registry instanceof Registry);

        return $registry;
    }

    /**
     * @param \Closure(): bool $work
     */
    private function emails(\Closure $work): bool
    {
        try {
            return $work();
        } catch (\InvalidArgumentException $e) {
            throw new ScriptError($e->getMessage());
        }
    }

    /**
     * @param \Closure(): \OrillaCart\Extension\Order\OrderSummary $work
     */
    private function placed(\Closure $work): \OrillaCart\Extension\Order\OrderSummary
    {
        try {
            return $work();
        } catch (\InvalidArgumentException $e) {
            // What the shop refuses is an answer to the script, which may say so to whoever asked.
            throw new ScriptFailure(ScriptFailure::REFUSED, $e->getMessage());
        }
    }

    /**
     * @param \Closure(): bool $work
     */
    private function mark(\Closure $work): bool
    {
        try {
            return $work();
        } catch (\InvalidArgumentException $e) {
            throw new ScriptError($e->getMessage());
        }
    }
}
