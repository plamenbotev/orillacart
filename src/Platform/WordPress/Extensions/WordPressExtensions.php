<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Extensions;

use OrillaCart\Catalog\Application\AddOnProducts;
use OrillaCart\Catalog\Application\ProductTranslations;
use OrillaCart\Checkout\Application\AddOnOrderPrices;
use OrillaCart\Extension\Assistant\AssistantTool;
use OrillaCart\Extension\Cart\CartLineType;
use OrillaCart\Extension\Cart\CartRule;
use OrillaCart\Extension\Catalog\FindsProducts;
use OrillaCart\Extension\Catalog\ProductColumn;
use OrillaCart\Extension\Catalog\ProductFilter;
use OrillaCart\Extension\Catalog\ProductLanguages;
use OrillaCart\Extension\Catalog\Products;
use OrillaCart\Extension\Checkout\AddressCheck;
use OrillaCart\Extension\Checkout\CheckoutField;
use OrillaCart\Extension\Checkout\OrderFee;
use OrillaCart\Extension\Invoice\EInvoiceSender;
use OrillaCart\Extension\Log;
use OrillaCart\Extension\Marketing\MailingList;
use OrillaCart\Extension\Order\FindsOrders;
use OrillaCart\Extension\Order\OrderAction;
use OrillaCart\Extension\Order\OrderColumn;
use OrillaCart\Extension\Order\OrderFilter;
use OrillaCart\Extension\Order\OrderPrices;
use OrillaCart\Extension\Order\Orders;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Pricing\OrderAdjuster;
use OrillaCart\Extension\Pricing\PriceAdjuster;
use OrillaCart\Extension\Registry;
use OrillaCart\Extension\Shipping\ShippingMethod;
use OrillaCart\Extension\Storefront\ProductBlock;
use OrillaCart\Extension\Tax\TaxProvider;
use OrillaCart\Extension\Version;
use OrillaCart\Ordering\Application\OrderSummaries;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * The registry add-ons fill on the orillacart_extensions action. The action runs once, the first time the shop
 * needs what is registered (at the latest on wp_loaded, for the add-ons' product blocks), after the plugin's own
 * gateways are in: so every plugin is loaded by then. A callback hooked after the action ran is logged as a
 * warning, since nothing it registers is used.
 */
final class WordPressExtensions implements Registry, InstalledExtensions
{
    public const string ACTION = 'orillacart_extensions';
    /** The container tag of the plugin's own payment gateways (config/services.php). */
    public const string GATEWAY_TAG = 'orillacart.payment_gateway';

    /** @var array<string, PaymentGateway> */
    private array $gateways = [];
    /** @var array<string, ShippingMethod> */
    private array $methods = [];
    /** @var array<string, PriceAdjuster> */
    private array $adjusters = [];
    /** @var array<string, CartRule> */
    private array $cartRules = [];

    /** @var array<string, CartLineType> */
    private array $cartLineTypes = [];
    /** @var array<string, CheckoutField> */
    private array $fields = [];
    /** @var array<string, AddressCheck> */
    private array $addressChecks = [];
    /** @var array<string, OrderFee> */
    private array $fees = [];
    /** @var array<string, OrderAction> */
    private array $actions = [];
    /** @var array<string, MailingList> */
    private array $mailingLists = [];
    /** @var array<string, OrderColumn> */
    private array $orderColumns = [];
    /** @var array<string, ProductColumn> */
    private array $productColumns = [];
    /** @var array<string, OrderFilter> */
    private array $orderFilters = [];
    /** @var array<string, FindsOrders> */
    private array $orderSearches = [];
    /** @var array<string, ProductFilter> */
    private array $productFilters = [];
    /** @var array<string, FindsProducts> */
    private array $productSearches = [];
    /** @var array<string, TaxProvider> */
    private array $taxProviders = [];

    /** @var array<string, EInvoiceSender> */
    private array $eInvoiceSenders = [];
    /** @var array<string, OrderAdjuster> */
    private array $orderAdjusters = [];

    /** @var array<string, AssistantTool> */
    private array $assistantTools = [];
    /** @var array<string, ProductBlock> */
    private array $productBlocks = [];
    private bool $collected = false;
    private bool $collecting = false;
    /** @var array<string, true> the action's callbacks when it ran, by priority and key */
    private array $heard = [];

    /**
     * @param iterable<PaymentGateway> $builtInGateways
     * @param \Closure(): Orders        $orders          a closure: reading orders needs services that need this registry
     * @param \Closure(): Products      $products        a closure, as orders
     * @param \Closure(): OrderPrices   $orderPrices     a closure, as orders
     * @param \Closure(): ProductLanguages $productLanguages a closure, as orders
     */
    public function __construct(
        #[AutowireIterator(self::GATEWAY_TAG)]
        private readonly iterable $builtInGateways,
        private readonly LoggerInterface $logger,
        #[AutowireServiceClosure(OrderSummaries::class)]
        private readonly \Closure $orders,
        #[AutowireServiceClosure(AddOnProducts::class)]
        private readonly \Closure $products,
        #[AutowireServiceClosure(AddOnOrderPrices::class)]
        private readonly \Closure $orderPrices,
        #[AutowireServiceClosure(ProductTranslations::class)]
        private readonly \Closure $productLanguages,
    ) {}

    public function version(): int
    {
        return Version::CURRENT;
    }

    public function paymentGateway(PaymentGateway $gateway): void
    {
        if ($this->accepts('payment gateway', $gateway->id(), $this->gateways[$gateway->id()] ?? null, $gateway)) {
            $this->gateways[$gateway->id()] = $gateway;
        }
    }

    public function shippingMethod(ShippingMethod $method): void
    {
        if ($this->accepts('shipping method', $method->id(), $this->methods[$method->id()] ?? null, $method)) {
            $this->methods[$method->id()] = $method;
        }
    }

    public function priceAdjuster(PriceAdjuster $adjuster): void
    {
        if ($this->accepts('price adjuster', $adjuster->id(), $this->adjusters[$adjuster->id()] ?? null, $adjuster)) {
            $this->adjusters[$adjuster->id()] = $adjuster;
        }
    }

    public function cartRule(CartRule $rule): void
    {
        if ($this->accepts('cart rule', $rule->id(), $this->cartRules[$rule->id()] ?? null, $rule)) {
            $this->cartRules[$rule->id()] = $rule;
        }
    }

    public function cartLineType(CartLineType $type): void
    {
        if ($this->accepts('cart line type', $type->id(), $this->cartLineTypes[$type->id()] ?? null, $type)) {
            $this->cartLineTypes[$type->id()] = $type;
        }
    }

    public function checkoutField(CheckoutField $field): void
    {
        if ($this->accepts('checkout field', $field->id, $this->fields[$field->id] ?? null, $field)) {
            $this->fields[$field->id] = $field;
        }
    }

    public function addressCheck(AddressCheck $check): void
    {
        if ($this->accepts('address check', $check->id(), $this->addressChecks[$check->id()] ?? null, $check)) {
            $this->addressChecks[$check->id()] = $check;
        }
    }

    public function orderFee(OrderFee $fee): void
    {
        if ($this->accepts('order fee', $fee->id(), $this->fees[$fee->id()] ?? null, $fee)) {
            $this->fees[$fee->id()] = $fee;
        }
    }

    public function mailingList(MailingList $list): void
    {
        if ($this->accepts('mailing list', $list->id(), $this->mailingLists[$list->id()] ?? null, $list)) {
            $this->mailingLists[$list->id()] = $list;
        }
    }

    public function orderAction(OrderAction $action): void
    {
        if ($this->accepts('order action', $action->id(), $this->actions[$action->id()] ?? null, $action)) {
            $this->actions[$action->id()] = $action;
        }
    }

    public function orderColumn(OrderColumn $column): void
    {
        if ($this->accepts('order column', $column->id(), $this->orderColumns[$column->id()] ?? null, $column)) {
            $this->orderColumns[$column->id()] = $column;
        }
    }

    public function productColumn(ProductColumn $column): void
    {
        if ($this->accepts('product column', $column->id(), $this->productColumns[$column->id()] ?? null, $column)) {
            $this->productColumns[$column->id()] = $column;
        }
    }

    public function orderFilter(OrderFilter $filter): void
    {
        if ($this->accepts('order filter', $filter->id(), $this->orderFilters[$filter->id()] ?? null, $filter)) {
            $this->orderFilters[$filter->id()] = $filter;
        }
    }

    public function orderSearch(FindsOrders $search): void
    {
        if ($this->accepts('order search', $search->id(), $this->orderSearches[$search->id()] ?? null, $search)) {
            $this->orderSearches[$search->id()] = $search;
        }
    }

    public function productFilter(ProductFilter $filter): void
    {
        if ($this->accepts('product filter', $filter->id(), $this->productFilters[$filter->id()] ?? null, $filter)) {
            $this->productFilters[$filter->id()] = $filter;
        }
    }

    public function productSearch(FindsProducts $search): void
    {
        if ($this->accepts('product search', $search->id(), $this->productSearches[$search->id()] ?? null, $search)) {
            $this->productSearches[$search->id()] = $search;
        }
    }

    public function orderAdjuster(OrderAdjuster $adjuster): void
    {
        if ($this->accepts('order adjuster', $adjuster->id(), $this->orderAdjusters[$adjuster->id()] ?? null, $adjuster)) {
            $this->orderAdjusters[$adjuster->id()] = $adjuster;
        }
    }

    public function orderPrices(): OrderPrices
    {
        return ($this->orderPrices)();
    }

    public function eInvoiceSender(EInvoiceSender $sender): void
    {
        if ($this->accepts('e-invoice sender', $sender->id(), $this->eInvoiceSenders[$sender->id()] ?? null, $sender)) {
            $this->eInvoiceSenders[$sender->id()] = $sender;
        }
    }

    public function eInvoiceSenders(): array
    {
        $this->collect();

        return array_values($this->eInvoiceSenders);
    }

    public function taxProvider(TaxProvider $provider): void
    {
        if ($this->accepts('tax provider', $provider->id(), $this->taxProviders[$provider->id()] ?? null, $provider)) {
            $this->taxProviders[$provider->id()] = $provider;
        }
    }

    public function assistantTool(AssistantTool $tool): void
    {
        if (!$this->collecting) {
            throw new \LogicException(\sprintf('Register an assistant tool on the %s action.', self::ACTION));
        }
        $name = $tool->name();
        if (isset($this->assistantTools[$name])) {
            $this->logger->error('Two of the assistant tools use the name "{id}": {first} and {second}. The second is left out.', ['id' => $name, 'first' => $this->assistantTools[$name]::class, 'second' => $tool::class]);

            return;
        }
        $this->assistantTools[$name] = $tool;
    }

    public function assistantTools(): array
    {
        $this->collect();

        return array_values($this->assistantTools);
    }

    public function productBlock(ProductBlock $block): void
    {
        if ($this->accepts('product block', $block->id(), $this->productBlocks[$block->id()] ?? null, $block)) {
            $this->productBlocks[$block->id()] = $block;
        }
    }

    public function productBlocks(): array
    {
        $this->collect();

        return array_values($this->productBlocks);
    }

    public function orders(): Orders
    {
        return ($this->orders)();
    }

    public function products(): Products
    {
        return ($this->products)();
    }

    public function productLanguages(): ProductLanguages
    {
        return ($this->productLanguages)();
    }

    public function log(): Log
    {
        return new ExtensionLog($this->logger);
    }

    public function paymentGateways(): array
    {
        $this->collect();

        return array_values($this->gateways);
    }

    public function shippingMethods(): array
    {
        $this->collect();

        return array_values($this->methods);
    }

    public function priceAdjusters(): array
    {
        $this->collect();

        return array_values($this->adjusters);
    }

    public function cartRules(): array
    {
        $this->collect();

        return array_values($this->cartRules);
    }

    public function cartLineTypes(): array
    {
        $this->collect();

        return array_values($this->cartLineTypes);
    }

    public function checkoutFields(): array
    {
        $this->collect();

        return array_values($this->fields);
    }

    public function addressChecks(): array
    {
        $this->collect();

        return array_values($this->addressChecks);
    }

    public function orderFees(): array
    {
        $this->collect();

        return array_values($this->fees);
    }

    public function mailingLists(): array
    {
        $this->collect();

        return array_values($this->mailingLists);
    }

    public function orderActions(): array
    {
        $this->collect();

        return array_values($this->actions);
    }

    public function orderColumns(): array
    {
        $this->collect();

        return array_values($this->orderColumns);
    }

    public function productColumns(): array
    {
        $this->collect();

        return array_values($this->productColumns);
    }

    public function orderFilters(): array
    {
        $this->collect();

        return array_values($this->orderFilters);
    }

    public function orderSearches(): array
    {
        $this->collect();

        return array_values($this->orderSearches);
    }

    public function productFilters(): array
    {
        $this->collect();

        return array_values($this->productFilters);
    }

    public function productSearches(): array
    {
        $this->collect();

        return array_values($this->productSearches);
    }

    public function taxProviders(): array
    {
        $this->collect();

        return array_values($this->taxProviders);
    }

    public function orderAdjusters(): array
    {
        $this->collect();

        return array_values($this->orderAdjusters);
    }

    /**
     * Whether an add-on's registration can be kept: made on the action, with a well-formed id no one took.
     */
    private function accepts(string $kind, string $id, ?object $taken, object $registered): bool
    {
        if (!$this->collecting) {
            throw new \LogicException(\sprintf('Register a %s on the %s action.', $kind, self::ACTION));
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $id) !== 1) {
            $this->logger->error('A {kind} was registered with the id "{id}"; ids are lowercase letters, digits and underscores, up to 40. It is left out.', ['kind' => $kind, 'id' => $id]);

            return false;
        }
        if ($taken !== null) {
            $this->logger->error('Two of the {kind}s use the id "{id}": {first} and {second}. The second is left out.', ['kind' => $kind, 'id' => $id, 'first' => $taken::class, 'second' => $registered::class]);

            return false;
        }

        return true;
    }

    private function collect(): void
    {
        if ($this->collected) {
            $this->warnOfLateCallbacks();

            return;
        }
        $this->collected = true;
        $this->collecting = true;
        try {
            foreach ($this->builtInGateways as $gateway) {
                $this->paymentGateway($gateway);
            }
            if (\function_exists('do_action')) {
                $this->isolateCallbacks();
                do_action(self::ACTION, $this);
                $this->heard = array_fill_keys(array_keys(self::callbackKeys()), true);
            }
        } finally {
            $this->collecting = false;
        }
    }

    /**
     * Each add-on registers on its own: one that throws is logged and left with what it registered before it
     * threw, and the add-ons after it still register.
     */
    private function isolateCallbacks(): void
    {
        global $wp_filter;
        $hook = \is_array($wp_filter) ? ($wp_filter[self::ACTION] ?? null) : null;
        if (!$hook instanceof \WP_Hook) {
            return;
        }
        $isolated = [];
        foreach ($hook->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $key => $callback) {
                $function = $callback['function'];
                $isolated[$priority][$key] = ['function' => function (mixed ...$arguments) use ($function): void {
                    try {
                        $function(...$arguments);
                    } catch (\Throwable $e) {
                        // One broken add-on does not take the shop's checkout, or the add-ons after it, with it.
                        $this->logger->error('An add-on failed while registering ({callback}): {message}', ['callback' => self::describe($function), 'message' => $e->getMessage(), 'exception' => $e]);
                    }
                }, 'accepted_args' => $callback['accepted_args']];
            }
        }
        $hook->callbacks = $isolated;
    }

    /**
     * An add-on hooked on the action after it ran (in a late hook, or after the shop read the registry): it would
     * lose everything it registers, its payment methods too, without a word. Each is logged once.
     */
    private function warnOfLateCallbacks(): void
    {
        foreach (self::callbackKeys() as $key => $callback) {
            if (isset($this->heard[$key])) {
                continue;
            }
            $this->heard[$key] = true;
            $this->logger->warning('An add-on hooked {action} after the shop read what add-ons register ({callback}); nothing it registers is used. Hook it when the plugin loads.', ['action' => self::ACTION, 'callback' => self::describe($callback)]);
        }
    }

    /** @return array<string, mixed> the action's callbacks, by priority and key */
    private static function callbackKeys(): array
    {
        global $wp_filter;
        $hook = \is_array($wp_filter) ? ($wp_filter[self::ACTION] ?? null) : null;
        if (!$hook instanceof \WP_Hook) {
            return [];
        }
        $keys = [];
        foreach ($hook->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $key => $callback) {
                $keys["{$priority}/{$key}"] = $callback['function'];
            }
        }

        return $keys;
    }

    /** Where a callback is, for the shop to find the add-on: a function or method name, or a closure's file and line. */
    private static function describe(mixed $callback): string
    {
        try {
            return match (true) {
                \is_string($callback) => $callback,
                \is_array($callback) && \count($callback) === 2 && \is_string($callback[1]) => (\is_object($callback[0]) ? $callback[0]::class : (\is_string($callback[0]) ? $callback[0] : '?')) . '::' . $callback[1],
                $callback instanceof \Closure => self::closureAt(new \ReflectionFunction($callback)),
                \is_object($callback) => $callback::class,
                default => 'an unknown callback',
            };
        } catch (\ReflectionException) {
            return 'an unknown callback';
        }
    }

    private static function closureAt(\ReflectionFunction $function): string
    {
        return \sprintf('a closure in %s:%d', (string) $function->getFileName(), (int) $function->getStartLine());
    }
}
