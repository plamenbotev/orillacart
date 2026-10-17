<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Checkout\Application\Dto\CheckoutTaxView;
use OrillaCart\Checkout\Application\Dto\CheckoutView;
use OrillaCart\Checkout\Application\Dto\ShippingChoiceView;
use OrillaCart\Payment\Application\Dto\PaymentOptionView;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Tests\Fixtures\Checkout\LegacyCheckout;
use PHPUnit\Framework\TestCase;

/**
 * The rebuilt checkout gives the old plugin's answers (recorded in legacy-checkout.json by
 * record-legacy-checkout.php): line prices, taxes, which shipping rates are offered at what price and tax,
 * totals, and which payment methods are offered. Where the old answer was wrong, the expectation is corrected
 * and the correction counted, so every difference has a name:
 *
 * - "shop address taxes": taxing by the shop's address added up the rates of every country in the tax group
 *   (the tax bug found in Phase 2); the shop is in Bulgaria, so its Bulgarian rates apply.
 * - "rates without an address": before the customer gave an address, every country's rates were offered;
 *   now only rates that ship everywhere are.
 * - "per item counts only what ships": a per-item rate charged for downloads and services too.
 * - "half cents round up": amounts such as 2.175 were rounded as floats, sometimes down (2.17); they are exact
 *   now and round half up (2.18).
 * - "totals add up": the old order total was rounded on its own and could differ by a cent from the sum of its
 *   rounded parts.
 *
 * Shipping rates are compared only when something ships: the old checkout listed rates for downloads too, but
 * never charged them.
 *
 * @phpstan-import-type Recorded from LegacyCheckout
 */
final class LegacyCheckoutRulesTest extends TestCase
{
    private const array CORRECTIONS = [
        'unchanged' => 147,
        'shop address taxes' => 56,
        'rates without an address' => 20,
        'per item counts only what ships' => 8,
        'half cents round up' => 3,
        'totals add up' => 5,
    ];

    /** @var Recorded */
    private static array $recorded;

    public static function setUpBeforeClass(): void
    {
        $recorded = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/Checkout/legacy-checkout.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($recorded);
        /** @var Recorded $recorded */
        self::$recorded = $recorded;
    }

    public function testCheckoutMatchesTheOldPluginExceptItsNamedBugs(): void
    {
        /** @var array<string, LegacyCheckout> $shops */
        $shops = [];
        $tally = array_fill_keys(array_keys(self::CORRECTIONS), 0);
        foreach (self::$recorded['cases'] as $case) {
            $setting = self::text($case['settings']);
            $shop = $shops[$setting] ??= new LegacyCheckout(self::$recorded, $setting);
            $reasons = [];
            $expected = $this->expected($case, $reasons);
            $actual = self::actual($shop->summary(self::text($case['cart']), self::text($case['customer'])), $shop);

            self::assertSame($expected, $actual, \sprintf('%s / %s / %s', $setting, self::text($case['cart']), self::text($case['customer'])));
            foreach ($reasons === [] ? ['unchanged'] : array_unique($reasons) as $reason) {
                ++$tally[$reason];
            }
        }

        self::assertSame(self::CORRECTIONS, $tally);
    }

    /**
     * @param array<string, mixed> $case
     * @param list<string>         $reasons
     *
     * @return array<string, mixed>
     */
    private function expected(array $case, array &$reasons): array
    {
        $setting = self::text($case['settings']);
        $customer = self::text($case['customer']);
        $cart = self::text($case['cart']);
        $source = $case;
        if ($setting === 'shop-address') {
            // The shop is in Bulgaria: its taxes are the ones a Bulgarian customer pays when taxing by address.
            $source = $this->case('shipping-address', $cart, 'bg');
            $reasons[] = 'shop address taxes';
        }

        $lines = [];
        foreach (self::rows($case['lines']) as $index => $line) {
            $lines[] = [self::decimal($line['unit']), (int) self::number($line['quantity']), self::cents(self::number(self::rows($source['lines'])[$index]['unit_tax']) * self::number($line['quantity']))];
        }
        $tax = self::cents($source['tax']);
        if ($tax !== self::cents(self::rows([$source['order']])[0]['tax'])) {
            $reasons[] = 'half cents round up';
        }
        $taxes = [];
        foreach (self::rows($source['taxes']) as $row) {
            if (self::number($row['amount']) !== 0.0) {
                $taxes[] = [self::text($row['name']), self::cents($row['amount'])];
            }
        }

        $needsShipping = (bool) $case['needs_shipping'];
        $rates = [];
        if ($needsShipping) {
            foreach (self::rows($case['rates']) as $rate) {
                $key = self::text($rate['rate']);
                $fields = $this->rate($key);
                if ($customer === 'nobody' && isset($fields['countries'])) {
                    $reasons[] = 'rates without an address';
                    continue;
                }
                $net = self::number($rate['gross']) - self::number($rate['tax']);
                $percent = $net === 0.0 ? 0.0 : self::number($rate['tax']) / $net;
                if (($fields['per_item'] ?? 'no') === 'yes') {
                    $shipped = $this->shippedItems($cart);
                    if ($shipped !== $this->items($cart)) {
                        $reasons[] = 'per item counts only what ships';
                        $net = self::number($fields['value']) * $shipped;
                    }
                }
                if ($setting === 'shop-address') {
                    $percent = $this->bulgarianPercent($fields);
                }
                $rates[] = [$key, self::cents($net), self::cents($net * $percent)];
            }
        }

        $chosen = $rates[0] ?? null;
        $order = self::rows([$case['order']])[0];
        $subtotal = self::cents($case['subtotal']);
        $total = (string) Money::of($subtotal)->add(Money::of($tax))->add(Money::of($chosen[1] ?? '0'))->add(Money::of($chosen[2] ?? '0'));
        $oldParts = Money::of(self::cents($order['subtotal']))->add(Money::of(self::cents($order['tax'])))->add(Money::of(self::cents($order['shipping'])))->add(Money::of(self::cents($order['shipping_tax'])));
        if (!$oldParts->equals(Money::of(self::cents($order['total'])))) {
            $reasons[] = 'totals add up';
        }

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'taxes' => $taxes,
            'needs_shipping' => $needsShipping,
            'rates' => $rates,
            'total' => $total,
            'payment' => $total === '0' ? [] : array_map(static fn(mixed $class): string => LegacyCheckout::GATEWAYS[self::text($class)], self::values($case['payment_methods'])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function actual(CheckoutView $view, LegacyCheckout $shop): array
    {
        return [
            'lines' => array_map(static fn($line): array => [$line->unitPrice, $line->quantity, (string) Money::of($line->tax)->rounded(2)], $view->lines),
            'subtotal' => $view->subtotal,
            'tax' => $view->itemsTax,
            'taxes' => array_values(array_map(static fn(CheckoutTaxView $tax): array => [$tax->name, $tax->items], array_filter($view->taxes, static fn(CheckoutTaxView $tax): bool => $tax->items !== '0'))),
            'needs_shipping' => $view->needsShipping,
            'rates' => array_map(static fn(ShippingChoiceView $option): array => [$shop->rateKeys[$option->rateId], $option->price, $option->tax], $view->shippingOptions),
            'total' => $view->total,
            'payment' => array_map(static fn(PaymentOptionView $method): string => $method->gateway, $view->paymentMethods),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function case(string $setting, string $cart, string $customer): array
    {
        foreach (self::$recorded['cases'] as $case) {
            if ([$case['settings'], $case['cart'], $case['customer']] === [$setting, $cart, $customer]) {
                return $case;
            }
        }

        throw new \LogicException("No recorded case {$setting} / {$cart} / {$customer}");
    }

    /**
     * @return array<string, mixed>
     */
    private function rate(string $key): array
    {
        foreach (self::$recorded['carriers'] as [, $rates]) {
            if (isset($rates[$key])) {
                return $rates[$key];
            }
        }

        throw new \LogicException("No recorded rate {$key}");
    }

    /**
     * The shop's own rate for a shipping rate's tax group (the default group when it names none).
     *
     * @param array<string, mixed> $rate
     */
    private function bulgarianPercent(array $rate): float
    {
        if (($rate['vat'] ?? 'global') === 'no') {
            return 0.0;
        }
        $group = isset($rate['tax_group']) ? self::text($rate['tax_group']) : 'standard';
        foreach (self::rows(self::$recorded['tax_rates']) as $row) {
            if ($row[0] === $group && $row[1] === 'BG') {
                return self::number($row[3]) / 100;
            }
        }

        return 0.0;
    }

    private function items(string $cart): int
    {
        return (int) array_sum(array_map(static fn(array $line): float => self::number($line[1]), self::rows(self::rows([self::$recorded['carts']])[0][$cart])));
    }

    private function shippedItems(string $cart): int
    {
        $products = self::rows([self::$recorded['products']])[0];

        return (int) array_sum(array_map(
            static fn(array $line): float => self::rows([$products[self::text($line[0])]])[0][0] === 'regular' ? self::number($line[1]) : 0.0,
            self::rows(self::rows([self::$recorded['carts']])[0][$cart]),
        ));
    }

    /** An amount rounded to cents, exactly and half up, from the old float. */
    private static function cents(mixed $value): string
    {
        return (string) Money::of(\sprintf('%.4F', round(self::number($value), 4)))->rounded(2);
    }

    private static function decimal(mixed $value): string
    {
        return (string) Money::of(\sprintf('%.4F', self::number($value)));
    }

    private static function number(mixed $value): float
    {
        return \is_int($value) || \is_float($value) || \is_string($value) ? (float) $value : throw new \LogicException('Not a number: ' . get_debug_type($value));
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \LogicException('Not text: ' . get_debug_type($value));
    }

    /**
     * @return list<mixed>
     */
    private static function values(mixed $value): array
    {
        return \is_array($value) ? array_values($value) : throw new \LogicException('Not a list');
    }

    /**
     * @return array<array-key, array<array-key, mixed>>
     */
    private static function rows(mixed $value): array
    {
        if (!\is_array($value)) {
            throw new \LogicException('Not rows');
        }

        return array_map(static fn(mixed $row): array => \is_array($row) ? $row : throw new \LogicException('Not a row'), $value);
    }
}
