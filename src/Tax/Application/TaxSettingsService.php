<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Shared\Domain\RuleViolation;
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
    ) {}

    public function current(): TaxSettingsView
    {
        return TaxSettingsView::fromSettings($this->settings->load());
    }

    /**
     * @throws RuleViolation for a default group that does not exist
     */
    public function update(UpdateTaxSettings $command): TaxSettingsView
    {
        if ($command->defaultGroupId !== null && $this->groups->find($command->defaultGroupId) === null) {
            throw new RuleViolation(\sprintf('There is no tax group %d.', $command->defaultGroupId), 'orillacart_unknown_tax_group');
        }

        $settings = new TaxSettings(TaxBasis::from($command->basis), $command->defaultGroupId, $command->pricesIncludeTax, $command->reverseCharge, $command->roundsEachLine);
        $this->settings->save($settings);

        return TaxSettingsView::fromSettings($settings);
    }
}
