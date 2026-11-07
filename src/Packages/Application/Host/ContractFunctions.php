<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Host;

use OrillaCart\Extension\Cart\CartRefusal;
use OrillaCart\Extension\Checkout\Fee;
use OrillaCart\Extension\Home\HomeLine;
use OrillaCart\Extension\Home\HomeTile;
use OrillaCart\Extension\Pricing\Adjustment;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\HostNamespace;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\ScriptError;

/**
 * What the other scripted contracts answer with: a cart rule's refusals, an order fee, a price adjustment, the
 * Home screen's tiles and lines, and HTML made safe for a product block.
 */
final readonly class ContractFunctions implements HostNamespace
{
    public function functions(): array
    {
        return [
            new HostFunction('cart.refuse', static fn(Context $c, mixed $message): CartRefusal => CartRefusal::cart(Values::text($message)), 1, 1, 'cart.refuse(message): the cart cannot be ordered as it is; the message says why'),
            new HostFunction('cart.refuse_line', static fn(Context $c, mixed $lineId, mixed $message): CartRefusal => CartRefusal::line((int) Values::text($lineId), Values::text($message)), 2, 2, 'cart.refuse_line(line_id, message): one line cannot be ordered as it is'),
            new HostFunction('fee.of', static fn(Context $c, mixed $label, mixed $amount, mixed $taxable = true, mixed $taxGroupId = null, mixed $asLine = false): Fee => new Fee(Values::text($label), Values::amount($amount) ?? '0', Ops::truthy($taxable), $taxGroupId === null ? null : (int) Values::text($taxGroupId), Ops::truthy($asLine)), 2, 5, 'fee.of(label, amount, taxable?, tax_group_id?, as_line?): a fee added to the order'),
            new HostFunction('price.adjustment', static fn(Context $c, mixed $label, mixed $amount, mixed $line = null, mixed $reason = ''): Adjustment => new Adjustment(Values::text($label), Values::amount($amount) ?? '0', $line === null ? null : (int) Values::text($line), Values::text($reason)), 2, 4, 'price.adjustment(label, amount, line?, reason?): an amount taken off (negative) or added to the order or one of its lines'),
            new HostFunction('home.tile', static fn(Context $c, mixed $label, mixed $value, mixed $detail = '', mixed $url = ''): HomeTile => new HomeTile(Values::text($label), Values::text($value), Values::text($detail), Values::text($url)), 2, 4, 'home.tile(label, value, detail?, url?): a figure on the Home screen'),
            new HostFunction('home.line', static function (Context $c, mixed $text, mixed $url = '', mixed $tone = 'warning'): HomeLine {
                $tone = Values::text($tone);
                if (!\in_array($tone, [HomeLine::ATTENTION, HomeLine::WARNING, HomeLine::INFO], true)) {
                    throw new ScriptError('home.line takes a tone of attention, warning or info');
                }

                return new HomeLine(Values::text($text), Values::text($url), $tone);
            }, 1, 3, 'home.line(text, url?, tone?): something that waits, on the Home screen; tone attention, warning or info'),
            new HostFunction('html.escape', static fn(Context $c, mixed $text): string => htmlspecialchars(Values::text($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 1, 1, 'html.escape(text): the text safe inside HTML'),
        ];
    }
}
