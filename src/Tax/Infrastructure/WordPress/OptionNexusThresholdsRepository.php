<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Infrastructure\WordPress;

use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use OrillaCart\Tax\Domain\NexusThreshold;
use OrillaCart\Tax\Domain\NexusThresholds;
use OrillaCart\Tax\Domain\NexusThresholdsRepository;
use Psr\Log\LoggerInterface;

/**
 * Keeps the economic nexus thresholds in one WordPress option, read only by the tax screen.
 */
final readonly class OptionNexusThresholdsRepository implements NexusThresholdsRepository, LifecycleListener, RemovesShopData
{
    public const string OPTION = 'orillacart_nexus_thresholds';

    public function __construct(private LoggerInterface $logger) {}

    public function load(): NexusThresholds
    {
        $stored = get_option(self::OPTION);
        if (!\is_array($stored)) {
            return NexusThresholds::defaults();
        }
        try {
            $states = [];
            foreach (\is_array($stored['states'] ?? null) ? $stored['states'] : [] as $state => $threshold) {
                $states[(string) $state] = self::threshold($threshold);
            }

            return new NexusThresholds(self::threshold($stored['everywhere'] ?? null), $states);
        } catch (RuleViolation|\InvalidArgumentException) {
            $this->logger->warning('Stored nexus thresholds are not valid, using the defaults.');

            return NexusThresholds::defaults();
        }
    }

    public function save(NexusThresholds $thresholds): void
    {
        update_option(self::OPTION, [
            'everywhere' => self::stored($thresholds->everywhere),
            'states' => array_map(self::stored(...), $thresholds->states),
        ], false);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }

    private static function threshold(mixed $stored): NexusThreshold
    {
        if (!\is_array($stored)) {
            throw new \InvalidArgumentException('Not a threshold.');
        }
        $sales = $stored['sales'] ?? null;
        $orders = $stored['orders'] ?? null;
        if (($sales !== null && !\is_string($sales)) || ($orders !== null && !\is_int($orders))) {
            throw new \InvalidArgumentException('Not a threshold.');
        }

        return new NexusThreshold($sales, $orders);
    }

    /** @return array{sales: ?string, orders: ?int} */
    private static function stored(NexusThreshold $threshold): array
    {
        return ['sales' => $threshold->sales, 'orders' => $threshold->orders];
    }
}
