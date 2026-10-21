<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application;

use OrillaCart\Settings\Application\Dto\CurrencySettingsView;
use OrillaCart\Settings\Domain\CurrencySettings;
use OrillaCart\Settings\Domain\CurrencySettingsRepository;
use OrillaCart\Settings\Domain\OfferedCurrency;
use OrillaCart\Settings\Domain\PriceRounding;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * The currencies a shopper may pay in: the shop's own, and those it offers at its own rates. Prices are kept in
 * the shop's currency; a shopper who chose another sees them, and pays them, converted and rounded as the shop
 * set for it. The rates are the shop's, kept until it changes them.
 */
final readonly class Currencies
{
    public function __construct(
        private CurrencySettingsRepository $settings,
        private SettingsService $shop,
        private CurrencyChoice $choice,
    ) {}

    public function settings(): CurrencySettingsView
    {
        return CurrencySettingsView::of($this->offered());
    }

    /**
     * @throws RuleViolation
     */
    public function save(CurrencySettingsView $command): CurrencySettingsView
    {
        $own = $this->shop->current()->currency;
        $offered = [];
        foreach ($command->offered as $currency) {
            if ($currency->code === $own) {
                throw new RuleViolation(\sprintf('%s is the shop’s own currency.', $own), 'orillacart_invalid_currency');
            }
            $offered[] = new OfferedCurrency(new Currency($currency->code), $currency->rate, PriceRounding::from($currency->rounding));
        }
        $settings = new CurrencySettings($offered);
        $this->settings->save($settings);

        return CurrencySettingsView::of($settings);
    }

    /** @return list<string> the shop's currency first, then those it offers */
    public function codes(): array
    {
        return [$this->shop->current()->currency, ...array_map(static fn(OfferedCurrency $offered): string => $offered->currency->code, $this->offered()->offered)];
    }

    /** The currency of this request's shopper: the one they chose, while the shop offers it, or the shop's. */
    public function current(): string
    {
        $chosen = $this->choice->chosen();

        return $chosen !== null && $this->offered()->find($chosen) !== null ? $chosen : $this->shop->current()->currency;
    }

    public function isOffered(string $code): bool
    {
        return \in_array($code, $this->codes(), true);
    }

    /** A price of the shop's in the currency, rounded as the shop set; as it is in the shop's own. */
    public function price(Money $price, string $currency): Money
    {
        return $this->find($currency)?->price($price) ?? $price;
    }

    /** Another amount of the shop's, such as a minimum or a coupon's, in the currency's decimals. */
    public function amount(Money $amount, string $currency): Money
    {
        return $this->find($currency)?->amount($amount) ?? $amount;
    }

    /** An amount in the currency back in the shop's. */
    public function back(Money $amount, string $currency): Money
    {
        return $this->find($currency)?->back($amount) ?? $amount;
    }

    /** Units of the currency for one of the shop's: "1" for its own. */
    public function rate(string $currency): string
    {
        return $this->find($currency)->rate ?? '1';
    }

    /** The decimals amounts in the currency are priced in: the shop's choice for its own, ISO 4217's for others. */
    public function decimals(string $currency): int
    {
        $shop = $this->shop->current();

        return $currency === $shop->currency ? $shop->priceDecimals : new Currency($currency)->decimals();
    }

    private function find(string $currency): ?OfferedCurrency
    {
        return $currency === $this->shop->current()->currency ? null : $this->offered()->find($currency);
    }

    private function offered(): CurrencySettings
    {
        return $this->settings->load()->without($this->shop->current()->currency);
    }
}
