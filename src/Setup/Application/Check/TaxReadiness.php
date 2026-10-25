<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Check;

use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Setup\Application\Dto\Readiness;
use OrillaCart\Setup\Application\ReadinessCheck;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Tax\Application\TaxGroupService;
use OrillaCart\Tax\Application\TaxSettingsService;

/**
 * Tax decided: a default group with a rate for the shop's own country, or taxes by the shop's address with none,
 * as a small business charges none. A tax service charges what the shop's rates leave out.
 */
final readonly class TaxReadiness implements ReadinessCheck
{
    public function __construct(
        private TaxSettingsService $tax,
        private TaxGroupService $groups,
        private SettingsService $settings,
    ) {}

    public function check(): array
    {
        $tax = $this->tax->current();
        $country = $this->settings->current()->shopCountry;
        if ($tax->provider !== null || $country === null) {
            return [];
        }
        if ($tax->defaultGroupId === null) {
            return $tax->basis === 'shop_address' ? [] : [Readiness::stop('tax.none', Text::of('No tax group is the default, so products charge no tax. Run the setup\'s tax step, or choose one in Tax.'), 'tax')];
        }
        $group = $this->groups->group($tax->defaultGroupId);
        foreach ($group->rates as $rate) {
            if ($rate->country === $country) {
                return [];
            }
        }

        return [Readiness::advice('tax.home', Text::of('The default tax group "%1$s" has no rate for %2$s, where the shop sells from.', $group->name, $country), 'tax')];
    }
}
