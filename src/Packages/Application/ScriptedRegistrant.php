<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Extension\Registry;
use OrillaCart\Packages\Application\Adapters\ScriptedCourier;
use OrillaCart\Packages\Application\Adapters\ScriptedPaymentGateway;
use OrillaCart\Packages\Application\Adapters\ScriptedRefundingGateway;
use OrillaCart\Packages\Application\Adapters\ScriptedShippingMethod;
use OrillaCart\Packages\Application\Dto\LoadedPackage;
use OrillaCart\Shared\Extension\Registrant;
use Psr\Log\LoggerInterface;

/**
 * Registers what the enabled extensions declare on the add-on registry, as a PHP add-on would: their payment
 * gateways and shipping methods, through adapters that run the scripts. One extension that cannot be read does
 * not keep the others out.
 */
final readonly class ScriptedRegistrant implements Registrant
{
    public function __construct(
        private PackageService $packages,
        private ScriptRunner $runner,
        private LoggerInterface $logger,
    ) {}

    public function register(Registry $registry): void
    {
        foreach ($this->packages->loaded() as $loaded) {
            try {
                foreach ($this->providers($loaded) as $provider) {
                    if ($provider instanceof ScriptedPaymentGateway) {
                        $registry->paymentGateway($provider);
                    } elseif ($provider instanceof ScriptedShippingMethod) {
                        $registry->shippingMethod($provider);
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('The extension "{extension}" could not register: {message}', ['extension' => $loaded->package->slug(), 'message' => $e->getMessage(), 'exception' => $e]);
                $this->packages->failed($loaded->package->slug(), $e->getMessage());
            }
        }
    }

    /**
     * The providers an extension declares, as the contracts they answer: a gateway with `refund` refunds, a
     * shipping method with `create_shipment` books.
     *
     * @return list<ScriptedPaymentGateway|ScriptedShippingMethod>
     */
    public function providers(LoadedPackage $loaded): array
    {
        $providers = [];
        foreach ($loaded->manifest->ofKind('payment_gateway') as $declaration) {
            $providers[] = ScriptRunner::has($declaration, 'refund')
                ? new ScriptedRefundingGateway($loaded, $declaration, $this->runner)
                : new ScriptedPaymentGateway($loaded, $declaration, $this->runner);
        }
        foreach ($loaded->manifest->ofKind('shipping_method') as $declaration) {
            $providers[] = ScriptRunner::has($declaration, 'create_shipment')
                ? new ScriptedCourier($loaded, $declaration, $this->runner)
                : new ScriptedShippingMethod($loaded, $declaration, $this->runner);
        }

        return $providers;
    }
}
