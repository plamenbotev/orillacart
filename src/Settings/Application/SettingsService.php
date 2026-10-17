<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application;

use OrillaCart\Settings\Application\Dto\SettingsView;
use OrillaCart\Settings\Application\Dto\UpdateSettings;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\SettingsChanged;
use OrillaCart\Settings\Domain\SettingsRepository;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Use cases for the shop settings. Other areas read settings through current().
 */
final readonly class SettingsService
{
    public function __construct(
        private SettingsRepository $settings,
        private EventDispatcherInterface $events,
    ) {}

    public function current(): SettingsView
    {
        return SettingsView::fromSettings($this->settings->load());
    }

    public function update(UpdateSettings $command): SettingsView
    {
        $previous = $this->settings->load();
        $current = new StoreSettings(
            new Currency($command->currency),
            $command->priceDecimals,
            WeightUnit::from($command->weightUnit),
            DimensionUnit::from($command->dimensionUnit),
        );

        if (!$current->equals($previous)) {
            $this->settings->save($current);
            $this->events->dispatch(new SettingsChanged($previous, $current));
        }

        return SettingsView::fromSettings($current);
    }
}
