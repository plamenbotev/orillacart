<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Settings\Application\Dto\SettingsView;
use OrillaCart\Settings\Application\Dto\ShopLocation;
use OrillaCart\Settings\Application\Dto\UpdateSettings;
use OrillaCart\Settings\Domain\CurrencyPosition;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\SettingsChanged;
use OrillaCart\Settings\Domain\SettingsRepository;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Use cases for the shop settings. Other areas read settings through current().
 */
final readonly class SettingsService
{
    public function __construct(
        private SettingsRepository $settings,
        private EventDispatcherInterface $events,
        private GeographyService $geography,
    ) {}

    public function current(): SettingsView
    {
        return SettingsView::fromSettings($this->settings->load());
    }

    /**
     * @throws RuleViolation when a value breaks a rule, such as a state outside the shop country
     */
    public function update(UpdateSettings $command): SettingsView
    {
        if ($command->shopCountry !== null) {
            $this->geography->require($command->shopCountry, $command->shopState);
        }
        $sellTo = array_values(array_unique($command->sellTo));
        foreach ($sellTo as $country) {
            $this->geography->require($country, null);
        }

        $previous = $this->settings->load();
        $current = new StoreSettings(
            new Currency($command->currency),
            $command->fewerDecimals,
            WeightUnit::from($command->weightUnit),
            DimensionUnit::from($command->dimensionUnit),
            $command->shopCountry,
            $command->shopState,
            $command->shopPostcode === null || trim($command->shopPostcode) === '' ? null : trim($command->shopPostcode),
            trim($command->currencySign),
            CurrencyPosition::from($command->currencyPosition),
            $command->catalogOnly,
            $command->hidePrices,
            $sellTo,
            $command->showLowestPrice,
            $command->removeDataOnUninstall,
            $command->lowStockThreshold,
            $command->unpaidHoldDays,
            $command->returnDays,
            $command->minimumOrder === null || $command->minimumOrder === '' ? null : Money::of($command->minimumOrder),
        );

        if (!$current->equals($previous)) {
            $this->settings->save($current);
            $this->events->dispatch(new SettingsChanged($previous, $current));
        }

        return SettingsView::fromSettings($current);
    }

    /**
     * Where the shop sells from, its currency and units, the other settings as they are. A new currency starts
     * with its own decimals.
     *
     * @throws RuleViolation
     */
    public function locate(ShopLocation $location): SettingsView
    {
        $current = $this->current();

        return $this->update(new UpdateSettings(
            $location->currency,
            $location->currency === $current->currency ? $current->fewerDecimals : null,
            $location->weightUnit,
            $location->dimensionUnit,
            $location->country,
            $location->state,
            $location->postcode,
            $location->currencySign,
            $location->currencyPosition,
            $current->catalogOnly,
            $current->hidePrices,
            $current->sellTo,
            $current->showLowestPrice,
            $current->removeDataOnUninstall,
            $current->lowStockThreshold,
            $current->unpaidHoldDays,
            $current->returnDays,
            $current->minimumOrder,
        ));
    }

    /**
     * The countries the shop sells to, empty for every country; the other settings as they are.
     *
     * @param list<string> $countries
     *
     * @throws RuleViolation
     */
    public function sellTo(array $countries): SettingsView
    {
        $current = $this->current();

        return $this->update(new UpdateSettings(
            $current->currency,
            $current->fewerDecimals,
            $current->weightUnit,
            $current->dimensionUnit,
            $current->shopCountry,
            $current->shopState,
            $current->shopPostcode,
            $current->currencySign,
            $current->currencyPosition,
            $current->catalogOnly,
            $current->hidePrices,
            $countries,
            $current->showLowestPrice,
            $current->removeDataOnUninstall,
            $current->lowStockThreshold,
            $current->unpaidHoldDays,
            $current->returnDays,
            $current->minimumOrder,
        ));
    }

    /**
     * The rules for selling to consumers: days to return, and showing a discount's lowest price of the last 30
     * days; the other settings as they are.
     *
     * @throws RuleViolation
     */
    public function consumerRules(?int $returnDays, bool $showLowestPrice): SettingsView
    {
        $current = $this->current();

        return $this->update(new UpdateSettings(
            $current->currency,
            $current->fewerDecimals,
            $current->weightUnit,
            $current->dimensionUnit,
            $current->shopCountry,
            $current->shopState,
            $current->shopPostcode,
            $current->currencySign,
            $current->currencyPosition,
            $current->catalogOnly,
            $current->hidePrices,
            $current->sellTo,
            $showLowestPrice,
            $current->removeDataOnUninstall,
            $current->lowStockThreshold,
            $current->unpaidHoldDays,
            $returnDays,
            $current->minimumOrder,
        ));
    }
}
