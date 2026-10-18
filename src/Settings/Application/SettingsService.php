<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Settings\Application\Dto\SettingsView;
use OrillaCart\Settings\Application\Dto\UpdateSettings;
use OrillaCart\Settings\Domain\CurrencyPosition;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\SettingsChanged;
use OrillaCart\Settings\Domain\SettingsRepository;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
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
            $command->priceDecimals,
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
        );

        if (!$current->equals($previous)) {
            $this->settings->save($current);
            $this->events->dispatch(new SettingsChanged($previous, $current));
        }

        return SettingsView::fromSettings($current);
    }
}
