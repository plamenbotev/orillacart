<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress;

use OrillaCart\Extension\Registry;
use OrillaCart\Packages\Application\Host\HourlyQuota;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\HostNamespace;
use OrillaCart\Scripting\Domain\ScriptError;
use OrillaCart\Scripting\Domain\ScriptFailure;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * `shop.orders` and `shop.products` as the add-on API lets add-ons reach them: an order by number, and the marks
 * the lists filter by; products and customers read, email sent, orders placed (design 61); products, variations,
 * attribute sets and categories made and changed (design 69). The registry is reached
 * lazily: it is what registers the scripted extensions.
 */
final readonly class ShopFunctions implements HostNamespace
{
    public function __construct(
        #[AutowireServiceClosure(Registry::class)]
        private \Closure $registry,
        private HourlyQuota $quota,
    ) {}

    public function functions(): array
    {
        return [
            new HostFunction('shop.orders.find', fn(Context $c, mixed $number): ?array => ($order = $this->registry()->orders()->find((int) Values::text($number))) === null ? null : Values::order($order), 1, 1, 'shop.orders.find(number): the order, or null', 'orders:read', HostFunction::QUERY, writes: false),
            new HostFunction('shop.orders.mark', fn(Context $c, mixed $number, mixed $mark): bool => $this->mark(fn() => $this->registry()->orders()->mark((int) Values::text($number), Values::text($mark))), 2, 2, 'shop.orders.mark(number, filter_id): puts the order under the extension\'s order filter; false when there is no such order', 'orders:write', writes: true),
            new HostFunction('shop.orders.unmark', function (Context $c, mixed $number, mixed $mark): null {
                $this->mark(function () use ($number, $mark): bool {
                    $this->registry()->orders()->unmark((int) Values::text($number), Values::text($mark));

                    return true;
                });

                return null;
            }, 2, 2, 'shop.orders.unmark(number, filter_id): takes the order out of the filter', 'orders:write', writes: true),
            new HostFunction('shop.orders.is_marked', fn(Context $c, mixed $number, mixed $mark): bool => $this->mark(fn() => $this->registry()->orders()->isMarked((int) Values::text($number), Values::text($mark))), 2, 2, 'shop.orders.is_marked(number, filter_id): whether the order is under the filter', 'orders:read', writes: false),
            new HostFunction('shop.products.mark', fn(Context $c, mixed $productId, mixed $mark): bool => $this->mark(fn() => $this->registry()->products()->mark((int) Values::text($productId), Values::text($mark))), 2, 2, 'shop.products.mark(product_id, filter_id): puts the product under the extension\'s product filter', 'products:write', writes: true),
            new HostFunction('shop.products.unmark', function (Context $c, mixed $productId, mixed $mark): null {
                $this->mark(function () use ($productId, $mark): bool {
                    $this->registry()->products()->unmark((int) Values::text($productId), Values::text($mark));

                    return true;
                });

                return null;
            }, 2, 2, 'shop.products.unmark(product_id, filter_id): takes the product out of the filter', 'products:write', writes: true),
            new HostFunction('shop.products.find', fn(Context $c, mixed $productId): ?array => ($product = $this->registry()->products()->find((int) Values::text($productId))) === null ? null : Values::product($product), 1, 1, 'shop.products.find(product_id): the product as shoppers see it now ({ id, name, type, sku, price, regular_price, for_sale, available, on_backorder, variations[{ id, label, sku, price, regular_price, for_sale, available }] }), or null', 'products:read', HostFunction::QUERY, writes: false),
            new HostFunction('shop.customers.find', fn(Context $c, mixed $customerId): ?array => ($customer = $this->registry()->customers()->find((int) Values::text($customerId))) === null ? null : Values::customer($customer), 1, 1, 'shop.customers.find(customer_id): the customer ({ id, email, first_name, last_name, registered_at, billing, shipping_address, group_ids, orders, addresses_from_order }), or null; where the account keeps no address, billing and shipping_address are their latest order\'s and addresses_from_order its number (else null)', 'customers:read', HostFunction::QUERY, writes: false),
            new HostFunction('shop.email.send', fn(Context $c, mixed $to, mixed $subject, mixed $body, mixed $replyTo = null): bool => $this->emails($c, fn(): bool => $this->registry()->emails()->send(Values::text($to), Values::text($subject), Values::text($body), Values::optionalText($replyTo))), 3, 4, 'shop.email.send(to, subject, html, reply_to?): an email in the shop\'s layout, from its address; true when the mail system took it', 'email:send', HostFunction::EMAIL, static fn(Context $c, mixed $to, mixed $subject, mixed $body, mixed $replyTo = null): bool => true, writes: true),
            new HostFunction('shop.orders.create', fn(Context $c, mixed $order): array => Values::order($this->placed(fn() => $this->registry()->orderCreation()->create($this->request($c, \is_array($order) ? $order : [])))), 1, 1, 'shop.orders.create({ email, customer_id, billing, shipping_address, items: [{ product_id, quantity, option_ids, unit_price }], shipping_option, payment, paid, note, fields }): places the order as the shop does by hand, and answers the order map', 'orders:create', HostFunction::QUERY, fn(Context $c, mixed $order): array => Values::order($this->placed(fn() => $this->registry()->orderCreation()->create(Values::orderRequest(['test' => true, 'email_customer' => false] + (\is_array($order) ? $order : []))))), writes: true),
            ...$this->catalog(),
            new HostFunction('shop.products.is_marked', fn(Context $c, mixed $productId, mixed $mark): bool => $this->mark(fn() => $this->registry()->products()->isMarked((int) Values::text($productId), Values::text($mark))), 2, 2, 'shop.products.is_marked(product_id, filter_id): whether the product is under the filter', 'products:read', writes: false),
        ];
    }

    /**
     * The catalogue made and changed (design 69): products as drafts until published, their variations, attribute
     * sets and categories. In tests each works as it does, inside the test's rolled-back transaction, and is listed
     * in the result's effects.
     *
     * @return list<HostFunction>
     */
    private function catalog(): array
    {
        $writing = fn() => $this->registry()->productWriting();
        $functions = [
            ['shop.products.create', fn(Context $c, mixed $product): array => Values::product($writing()->create(Values::productDraft(self::map($product, 'shop.products.create')))), 1, 1, 'shop.products.create({ name, price, type, sku, sale_price, description, short_description, categories: ["A > B"], tags, stock, stockrooms: { id: level }, stock_mode, weight, featured, attributes: [{ name, options, required }], attribute_set_ids }): makes a draft product, as an import row would, and answers the product map; shop.products.publish puts it on sale', 'products:write'],
            ['shop.products.change', fn(Context $c, mixed $id, mixed $product): array => Values::product($writing()->change(self::id($id), Values::productDraft(self::map($product, 'shop.products.change')))), 2, 2, 'shop.products.change(product_id, { …as create }): changes what the map gives; attributes replace the product\'s own, keeping the ids of those named alike', 'products:write'],
            ['shop.products.add_variation', fn(Context $c, mixed $id, mixed $variation): array => Values::variation($writing()->addVariation(self::id($id), Values::variationDraft(self::map($variation, 'shop.products.add_variation')))), 2, 2, 'shop.products.add_variation(product_id, { options: { Colour: "Red" }, price, sku, sale_price, stock }): a variation of the options the product offers; price is the product\'s when left out', 'products:write'],
            ['shop.products.change_variation', fn(Context $c, mixed $id, mixed $variationId, mixed $variation): array => Values::variation($writing()->changeVariation(self::id($id), self::id($variationId), Values::variationDraft(self::map($variation, 'shop.products.change_variation')))), 3, 3, 'shop.products.change_variation(product_id, variation_id, { price, sku, sale_price, stock })', 'products:write'],
            ['shop.products.publish', function (Context $c, mixed $id) use ($writing): null {
                $writing()->publish(self::id($id));

                return null;
            }, 1, 1, 'shop.products.publish(product_id): puts the product on sale; shoppers see it', 'products:publish'],
            ['shop.products.unpublish', function (Context $c, mixed $id) use ($writing): null {
                $writing()->unpublish(self::id($id));

                return null;
            }, 1, 1, 'shop.products.unpublish(product_id): takes it back to a draft', 'products:publish'],
            ['shop.products.trash', function (Context $c, mixed $id) use ($writing): null {
                $writing()->trash(self::id($id));

                return null;
            }, 1, 1, 'shop.products.trash(product_id): moves it to the trash; placed orders keep it', 'products:publish'],
            ['shop.attributes.save_set', function (Context $c, mixed $set): array {
                $set = self::map($set, 'shop.attributes.save_set');
                $id = isset($set['id']) ? self::id($set['id']) : null;

                return Values::attributeSet($this->registry()->attributeSets()->save($id, Values::text($set['name'] ?? ''), Values::attributeDrafts($set['attributes'] ?? []), ($set['active'] ?? true) !== false));
            }, 1, 1, 'shop.attributes.save_set({ id, name, attributes: [{ name, options, required }], active }): makes an attribute set (no id) or replaces one\'s attributes, keeping the ids of those named alike', 'products:write'],
            ['shop.categories.ensure', fn(Context $c, mixed $path): int => $this->registry()->categories()->ensure(Values::text($path)), 1, 1, 'shop.categories.ensure("Lighting > Desk lamps"): the category\'s id, made with its parents where missing', 'products:write'],
        ];
        $made = [];
        foreach ($functions as [$name, $callable, $least, $most, $summary, $needs]) {
            $refusing = fn(Context $c, mixed ...$arguments): mixed => $this->refusing(static fn(): mixed => $callable($c, ...$arguments));
            $made[] = new HostFunction($name, $refusing, $least, $most, $summary, $needs, HostFunction::QUERY, $refusing, writes: true);
        }
        $made[] = new HostFunction('shop.products.search', function (Context $c, mixed $query = null): array {
            $query = $query === null ? [] : self::map($query, 'shop.products.search');

            return array_map(Values::product(...), $this->registry()->products()->search(
                Values::optionalText($query['sku'] ?? null),
                Values::optionalText($query['words'] ?? null),
                isset($query['category_id']) ? self::id($query['category_id']) : null,
                isset($query['limit']) ? (int) Values::text($query['limit']) : 20,
            ));
        }, 0, 1, 'shop.products.search({ sku, words, category_id, limit }): products by SKU, name or category, drafts too, newest first; up to limit (20, at most 100)', 'products:read', HostFunction::QUERY, writes: false);
        $made[] = new HostFunction('shop.attributes.sets', fn(Context $c): array => array_map(Values::attributeSet(...), $this->registry()->attributeSets()->all()), 0, 0, 'shop.attributes.sets(): the attribute sets, each { id, name, active, attributes: [{ id, name, required, options: [{ id, name }] }] }', 'products:read', HostFunction::QUERY, writes: false);
        $made[] = new HostFunction('shop.categories.list', fn(Context $c): array => array_map(Values::category(...), $this->registry()->categories()->all()), 0, 0, 'shop.categories.list(): every product category { id, name, parent_id, path, products }, by path', 'products:read', HostFunction::QUERY, writes: false);

        return $made;
    }

    /** @return array<mixed> */
    private static function map(mixed $value, string $function): array
    {
        return \is_array($value) ? $value : throw new ScriptError(\sprintf('%s takes a map.', $function));
    }

    private static function id(mixed $value): int
    {
        $id = (int) Values::text($value);

        return $id > 0 ? $id : throw new ScriptError(\sprintf('"%s" is not an id.', Values::text($value)));
    }

    /**
     * What the shop refuses is an answer to the script, which may say so to whoever asked.
     *
     * @param \Closure(): mixed $work
     */
    private function refusing(\Closure $work): mixed
    {
        try {
            return $work();
        } catch (\InvalidArgumentException $e) {
            throw new ScriptFailure(ScriptFailure::REFUSED, $e->getMessage());
        }
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
    private function emails(Context $c, \Closure $work): bool
    {
        // Fifty an hour for the extension, whoever asks: a public route is not a way to send the shop's mail.
        $this->quota->take($c, HourlyQuota::EMAILS, 'emails');
        try {
            return $work();
        } catch (\InvalidArgumentException $e) {
            throw new ScriptError($e->getMessage());
        }
    }

    /**
     * What the script asked to place. One that emails the customer sends mail as shop.email.send does: it needs
     * `email:send`, counts against the run's emails and the extension's hour, and the address is the order's.
     *
     * @param array<array-key, mixed> $order
     */
    private function request(Context $c, array $order): \OrillaCart\Extension\Order\NewOrderRequest
    {
        $request = Values::orderRequest($order);
        if ($request->emailCustomer && !$request->test) {
            $c->mails('shop.orders.create');
            $this->quota->take($c, HourlyQuota::EMAILS, 'emails');
        }

        return $request;
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
