<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Extension\Registry;
use OrillaCart\Packages\Application\Adapters\ScriptedAssistantTool;
use OrillaCart\Packages\Application\Adapters\ScriptedCartRule;
use OrillaCart\Packages\Application\Adapters\ScriptedColumn;
use OrillaCart\Packages\Application\Adapters\ScriptedCourier;
use OrillaCart\Packages\Application\Adapters\ScriptedFieldCheck;
use OrillaCart\Packages\Application\Adapters\ScriptedFilter;
use OrillaCart\Packages\Application\Adapters\ScriptedHomeContributor;
use OrillaCart\Packages\Application\Adapters\ScriptedMailingList;
use OrillaCart\Packages\Application\Adapters\ScriptedOrderAction;
use OrillaCart\Packages\Application\Adapters\ScriptedOrderAdjuster;
use OrillaCart\Packages\Application\Adapters\ScriptedOrderFee;
use OrillaCart\Packages\Application\Adapters\ScriptedPaymentGateway;
use OrillaCart\Packages\Application\Adapters\ScriptedPriceAdjuster;
use OrillaCart\Packages\Application\Adapters\ScriptedProductBlock;
use OrillaCart\Packages\Application\Adapters\ScriptedProvider;
use OrillaCart\Packages\Application\Adapters\ScriptedRefundingGateway;
use OrillaCart\Packages\Application\Adapters\ScriptedShippingMethod;
use OrillaCart\Packages\Application\Dto\LoadedPackage;
use OrillaCart\Shared\Extension\Registrant;
use Psr\Log\LoggerInterface;

/**
 * Registers what the enabled extensions declare on the add-on registry, as a PHP add-on would, through adapters
 * that run the scripts: payment gateways, shipping methods, cart rules, order fees, price and order adjusters,
 * checkout fields, order actions, columns, filters and searches, Home contributors, assistant tools, product
 * blocks and mailing lists. One extension that cannot be read does not keep the others out.
 */
final readonly class ScriptedRegistrant implements Registrant
{
    /** The declaration kinds and what they are registered as. */
    public const array KINDS = ['payment_gateway', 'shipping_method', 'cart_rule', 'order_fee', 'price_adjuster', 'order_adjuster', 'checkout_field', 'order_action', 'order_column', 'product_column', 'order_filter', 'product_filter', 'home', 'assistant_tool', 'product_block', 'mailing_list'];

    public function __construct(
        private PackageService $packages,
        private ScriptRunner $runner,
        private HtmlCleaner $cleaner,
        private LoggerInterface $logger,
    ) {}

    public function register(Registry $registry): void
    {
        foreach ($this->packages->loaded() as $loaded) {
            try {
                foreach ($this->providers($loaded) as $provider) {
                    $this->put($registry, $provider);
                }
            } catch (\Throwable $e) {
                $this->logger->error('The extension "{extension}" could not register: {message}', ['extension' => $loaded->package->slug(), 'message' => $e->getMessage(), 'exception' => $e]);
                $this->packages->failed($loaded->package->slug(), $e->getMessage());
            }
        }
    }

    /**
     * The providers an extension declares, as the contracts they answer: a gateway with `refund` refunds, a
     * shipping method with `create_shipment` books, a filter with `find` searches.
     *
     * @return list<ScriptedProvider>
     */
    public function providers(LoadedPackage $loaded): array
    {
        $providers = [];
        foreach ($loaded->manifest->all() as $declaration) {
            $provider = match ($declaration['kind']) {
                'payment_gateway' => ScriptRunner::has($declaration, 'refund') ? new ScriptedRefundingGateway($loaded, $declaration, $this->runner) : new ScriptedPaymentGateway($loaded, $declaration, $this->runner),
                'shipping_method' => ScriptRunner::has($declaration, 'create_shipment') ? new ScriptedCourier($loaded, $declaration, $this->runner) : new ScriptedShippingMethod($loaded, $declaration, $this->runner),
                'cart_rule' => new ScriptedCartRule($loaded, $declaration, $this->runner),
                'order_fee' => new ScriptedOrderFee($loaded, $declaration, $this->runner),
                'price_adjuster' => new ScriptedPriceAdjuster($loaded, $declaration, $this->runner),
                'order_adjuster' => new ScriptedOrderAdjuster($loaded, $declaration, $this->runner),
                'checkout_field' => new ScriptedFieldCheck($loaded, $declaration, $this->runner),
                'order_action' => new ScriptedOrderAction($loaded, $declaration, $this->runner),
                'order_column', 'product_column' => new ScriptedColumn($loaded, $declaration, $this->runner),
                'order_filter', 'product_filter' => new ScriptedFilter($loaded, $declaration, $this->runner),
                'home' => new ScriptedHomeContributor($loaded, $declaration, $this->runner),
                'assistant_tool' => new ScriptedAssistantTool($loaded, $declaration, $this->runner),
                'product_block' => new ScriptedProductBlock($loaded, $declaration, $this->runner, $this->cleaner),
                'mailing_list' => new ScriptedMailingList($loaded, $declaration, $this->runner),
                default => null,
            };
            if ($provider !== null) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    private function put(Registry $registry, ScriptedProvider $provider): void
    {
        $kind = $provider->kind();
        match (true) {
            $provider instanceof ScriptedPaymentGateway => $registry->paymentGateway($provider),
            $provider instanceof ScriptedShippingMethod => $registry->shippingMethod($provider),
            $provider instanceof ScriptedCartRule => $registry->cartRule($provider),
            $provider instanceof ScriptedOrderFee => $registry->orderFee($provider),
            $provider instanceof ScriptedPriceAdjuster => $registry->priceAdjuster($provider),
            $provider instanceof ScriptedOrderAdjuster => $registry->orderAdjuster($provider),
            $provider instanceof ScriptedFieldCheck => $registry->checkoutField($provider->field()),
            $provider instanceof ScriptedOrderAction => $registry->orderAction($provider),
            $provider instanceof ScriptedColumn && $kind === 'order_column' => $registry->orderColumn($provider),
            $provider instanceof ScriptedColumn => $registry->productColumn($provider),
            $provider instanceof ScriptedFilter && $kind === 'order_filter' => $this->orderFilter($registry, $provider),
            $provider instanceof ScriptedFilter => $this->productFilter($registry, $provider),
            $provider instanceof ScriptedHomeContributor => $registry->homeContributor($provider),
            $provider instanceof ScriptedAssistantTool => $registry->assistantTool($provider),
            $provider instanceof ScriptedProductBlock => $registry->productBlock($provider),
            $provider instanceof ScriptedMailingList => $registry->mailingList($provider),
            default => null,
        };
    }

    private function orderFilter(Registry $registry, ScriptedFilter $filter): void
    {
        $registry->orderFilter($filter);
        if ($filter->searches()) {
            $registry->orderSearch($filter);
        }
    }

    private function productFilter(Registry $registry, ScriptedFilter $filter): void
    {
        $registry->productFilter($filter);
        if ($filter->searches()) {
            $registry->productSearch($filter);
        }
    }
}
