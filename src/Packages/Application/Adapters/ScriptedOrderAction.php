<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Order\OrderAction;
use OrillaCart\Extension\Order\OrderSummary;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Scripting\Domain\Ops;

/** `order_action "id" { label; fn available(order); fn run(order) }`: a button on the order; run answers what to tell the manager. */
final class ScriptedOrderAction extends ScriptedProvider implements OrderAction
{
    public function label(): string
    {
        $label = $this->declaration['props']['label'] ?? null;

        return \is_string($label) ? $this->loaded->word($label) : $this->title();
    }

    public function isAvailable(OrderSummary $order): bool
    {
        if (!$this->has('available')) {
            return true;
        }
        try {
            return Ops::truthy($this->call('available', [Values::order($order)]));
        } catch (HandlerFailed) {
            return false;
        }
    }

    public function run(OrderSummary $order): string
    {
        try {
            return Values::text($this->call('run', [Values::order($order)]));
        } catch (HandlerFailed $e) {
            return $e->getMessage();
        }
    }
}
