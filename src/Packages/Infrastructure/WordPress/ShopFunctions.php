<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress;

use OrillaCart\Extension\Registry;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\HostNamespace;
use OrillaCart\Scripting\Domain\ScriptError;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * `shop.orders` and `shop.products` as the add-on API lets add-ons reach them: an order by number, and the marks
 * the lists filter by. The registry is reached lazily: it is what registers the scripted extensions.
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
    private function mark(\Closure $work): bool
    {
        try {
            return $work();
        } catch (\InvalidArgumentException $e) {
            throw new ScriptError($e->getMessage());
        }
    }
}
