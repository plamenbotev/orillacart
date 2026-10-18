<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Extensions;

use OrillaCart\Extension\Log;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Registry;
use OrillaCart\Extension\Shipping\ShippingMethod;
use OrillaCart\Extension\Version;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The registry add-ons fill on the orillacart_extensions action. The action runs once, the first time the shop
 * needs what is registered, after the plugin's own gateways are in: so every plugin is loaded by then, and a
 * request that never pays or ships never runs it.
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
    private bool $collected = false;
    private bool $collecting = false;

    /**
     * @param iterable<PaymentGateway> $builtInGateways
     */
    public function __construct(
        #[AutowireIterator(self::GATEWAY_TAG)]
        private readonly iterable $builtInGateways,
        private readonly LoggerInterface $logger,
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
            return;
        }
        $this->collected = true;
        $this->collecting = true;
        try {
            foreach ($this->builtInGateways as $gateway) {
                $this->paymentGateway($gateway);
            }
            if (\function_exists('do_action')) {
                try {
                    do_action(self::ACTION, $this);
                } catch (\Throwable $e) {
                    // One broken add-on does not take the shop's checkout with it.
                    $this->logger->error('An add-on failed while registering: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
                }
            }
        } finally {
            $this->collecting = false;
        }
    }
}
