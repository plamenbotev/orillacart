<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Domain;

use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * A currency the shop shows and takes besides its own: how many of it one unit of the shop's currency is worth,
 * and how its prices are rounded.
 */
final readonly class OfferedCurrency
{
    /**
     * @param string $rate units of this currency for one of the shop's, such as "1.95583"
     */
    public function __construct(
        public Currency $currency,
        public string $rate,
        public PriceRounding $rounding = PriceRounding::Decimals,
    ) {
        if (preg_match('/^\d{1,8}(\.\d{1,8})?$/', $rate) !== 1 || Money::of('1')->at($rate)->isZero()) {
            throw new RuleViolation(\sprintf('%s needs a rate above nothing, with up to eight decimals, such as 1.95583.', $currency->code), 'orillacart_invalid_exchange_rate');
        }
    }

    /** A price of the shop's in this currency, rounded as the shop chose. */
    public function price(Money $price): Money
    {
        return $this->rounding->round($price->at($this->rate), $this->currency->decimals());
    }

    /** Any other amount of the shop's, such as a coupon's or a minimum, in this currency's decimals. */
    public function amount(Money $amount): Money
    {
        return $amount->at($this->rate)->rounded($this->currency->decimals());
    }

    /** An amount in this currency, back in the shop's, exact. */
    public function back(Money $amount): Money
    {
        return $amount->from($this->rate);
    }
}
