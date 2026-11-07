<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Cart\CartRefusal;
use OrillaCart\Extension\Cart\CartRule;
use OrillaCart\Extension\Cart\CheckedCart;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;
use OrillaCart\Scripting\Domain\Ops;

/** `cart_rule "id" { fn check(cart) }`: answers a list of cart.refuse() and cart.refuse_line(), or nothing. */
final class ScriptedCartRule extends ScriptedProvider implements CartRule
{
    public function check(CheckedCart $cart): array
    {
        try {
            $answer = $this->call('check', [Values::cart($cart)]);
        } catch (HandlerFailed $e) {
            return [CartRefusal::cart($e->getMessage())];
        }
        $refusals = [];
        foreach (Ops::iterable($answer === null ? [] : $answer) as $item) {
            $refusal = $this->expect($item, CartRefusal::class, 'check');
            \assert($refusal instanceof CartRefusal);
            $refusals[] = $refusal;
        }

        return $refusals;
    }
}
