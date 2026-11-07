<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress\Hooks;

use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Packages\Application\ExtensionJobHandler;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * `on order_placed fn(event)`: the shop's public events (the `orillacart_<event>` actions, fired after the
 * commit) each push a job per extension that listens, with the event's arguments; the handler runs in the
 * `extensions` lane, never in the request that fired the event.
 */
#[AsHook('orillacart_cart_item_added', method: 'heard', arguments: 5)]
#[AsHook('orillacart_cart_item_changed', method: 'heard', arguments: 5)]
#[AsHook('orillacart_cart_item_removed', method: 'heard', arguments: 5)]
#[AsHook('orillacart_catalog_items_removed', method: 'heard', arguments: 5)]
#[AsHook('orillacart_order_changed', method: 'heard', arguments: 5)]
#[AsHook('orillacart_order_deleted', method: 'heard', arguments: 5)]
#[AsHook('orillacart_order_needs_attention', method: 'heard', arguments: 5)]
#[AsHook('orillacart_order_note_added', method: 'heard', arguments: 5)]
#[AsHook('orillacart_order_paid', method: 'heard', arguments: 5)]
#[AsHook('orillacart_order_placed', method: 'heard', arguments: 5)]
#[AsHook('orillacart_order_refunded', method: 'heard', arguments: 5)]
#[AsHook('orillacart_order_status_changed', method: 'heard', arguments: 5)]
#[AsHook('orillacart_price_rules_changed', method: 'heard', arguments: 5)]
#[AsHook('orillacart_product_changed', method: 'heard', arguments: 5)]
#[AsHook('orillacart_settings_changed', method: 'heard', arguments: 5)]
#[AsHook('orillacart_stock_changed', method: 'heard', arguments: 5)]
#[AsHook('orillacart_tax_rates_changed', method: 'heard', arguments: 5)]
final readonly class ScriptedEvents
{
    public const string PREFIX = 'orillacart_';

    public function __construct(private PackageService $packages, private Jobs $jobs) {}

    public function heard(mixed ...$arguments): void
    {
        $action = current_action();
        if (!\is_string($action) || !str_starts_with($action, self::PREFIX)) {
            return;
        }
        $event = substr($action, \strlen(self::PREFIX));
        $plain = array_values(array_map(static fn(mixed $argument): mixed => \is_scalar($argument) || $argument === null || \is_array($argument) ? $argument : (string) json_encode($argument), $arguments));
        foreach ($this->packages->loaded() as $loaded) {
            foreach ($loaded->manifest->ofKind('on') as $declaration) {
                if (($declaration['words'][0] ?? null) !== $event || $declaration['handler'] === null) {
                    continue;
                }
                $this->jobs->push(ExtensionJobHandler::TYPE, ['extension' => $loaded->package->slug(), 'method' => $declaration['handler'], 'arguments' => [['event' => $event, 'arguments' => $plain] + self::named($event, $plain)], 'what' => 'on ' . $event]);
            }
        }
    }

    /**
     * The first argument by a name a script would guess: the order's number, the product's id.
     *
     * @param list<mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private static function named(string $event, array $arguments): array
    {
        $first = $arguments[0] ?? null;
        if (str_starts_with($event, 'order_')) {
            return ['order' => $first];
        }
        if (str_starts_with($event, 'product_') || str_starts_with($event, 'stock_')) {
            return ['product' => $first];
        }
        if (str_starts_with($event, 'cart_')) {
            return ['cart' => $first];
        }

        return [];
    }
}
