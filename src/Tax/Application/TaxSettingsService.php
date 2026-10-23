<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Extension\Tax\TaxProvider;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Extension\InstalledExtensions;
use OrillaCart\Tax\Application\Dto\TaxSettingsView;
use OrillaCart\Tax\Application\Dto\UpdateTaxSettings;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxGroupRepository;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tax\Domain\TaxSettingsRepository;

final readonly class TaxSettingsService
{
    public function __construct(
        private TaxSettingsRepository $settings,
        private TaxGroupRepository $groups,
        private InstalledExtensions $extensions,
    ) {}

    public function current(): TaxSettingsView
    {
        return TaxSettingsView::fromSettings($this->settings->load(), $this->providers());
    }

    /**
     * @throws RuleViolation for a default group that does not exist
     */
    public function update(UpdateTaxSettings $command): TaxSettingsView
    {
        if ($command->defaultGroupId !== null && $this->groups->find($command->defaultGroupId) === null) {
            throw new RuleViolation(\sprintf('There is no tax group %d.', $command->defaultGroupId), 'orillacart_unknown_tax_group');
        }

        if ($command->provider !== null && !\in_array($command->provider, array_column($this->providers(), 'id'), true)) {
            throw new RuleViolation(\sprintf('There is no tax service "%s": its add-on may be off.', $command->provider), 'orillacart_unknown_tax_provider');
        }
        $settings = new TaxSettings(TaxBasis::from($command->basis), $command->defaultGroupId, $command->pricesIncludeTax, $command->reverseCharge, $command->roundsEachLine, array_values(array_unique($command->originRegions)), $command->provider, $command->whenProviderFails);
        $this->settings->save($settings);

        return TaxSettingsView::fromSettings($settings, $this->providers());
    }

    /** @return list<array{id: string, label: string}> */
    private function providers(): array
    {
        return array_map(static fn(TaxProvider $provider): array => ['id' => $provider->id(), 'label' => $provider->label()], $this->extensions->taxProviders());
    }
}
