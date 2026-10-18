<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Extensions;

use OrillaCart\Extension\Log;
use OrillaCart\Extension\Payment\PaymentGateway;
use OrillaCart\Extension\Registry;
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
        if (!$this->collecting) {
            throw new \LogicException('Register payment gateways on the ' . self::ACTION . ' action.');
        }
        $id = $gateway->id();
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $id) !== 1) {
            $this->logger->error('An add-on registered a payment gateway with the id "{id}"; ids are lowercase letters, digits and underscores, up to 40. It is left out.', ['id' => $id]);

            return;
        }
        if (isset($this->gateways[$id])) {
            $this->logger->error('Two payment gateways use the id "{id}": {first} and {second}. The second is left out.', ['id' => $id, 'first' => $this->gateways[$id]::class, 'second' => $gateway::class]);

            return;
        }
        $this->gateways[$id] = $gateway;
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
