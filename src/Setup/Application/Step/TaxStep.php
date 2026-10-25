<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Step;

use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Setup\Application\Dto\PlannedChange;
use OrillaCart\Setup\Application\Dto\TaxAnswers;
use OrillaCart\Setup\Application\SetupAnswers;
use OrillaCart\Setup\Application\SetupStep;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Tax\Application\Dto\PresetGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Application\Dto\TaxPreset;
use OrillaCart\Tax\Application\Dto\TaxSetup;
use OrillaCart\Tax\Application\TaxPresets;
use OrillaCart\Tax\Application\TaxSettingsService;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxSettings;

/**
 * Tax: the groups and rates the country's profile gives, how the shop taxes and shows prices, and for a small
 * business, what its invoices say instead.
 */
final readonly class TaxStep implements SetupStep
{
    public const string KEY = 'tax';
    private const string SETTINGS = 'tax.settings';
    private const string NOTE = 'tax.note';
    private const string GROUP = 'tax.group.';

    public function __construct(
        private TaxPresets $presets,
        private TaxSettingsService $settings,
        private Invoices $invoices,
        private ShopFacts $facts,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function position(): int
    {
        return 30;
    }

    public function answers(): string
    {
        return TaxAnswers::class;
    }

    public function plan(SetupAnswers $answers): array
    {
        $preset = $this->preset($answers);
        if ($preset === null) {
            return [];
        }
        $changes = [];
        foreach ($preset->groups as $group) {
            $change = $this->group($group, $preset);
            if ($change !== null) {
                $changes[] = $change;
            }
        }
        // A group still to make changes the default group too.
        if ($changes !== [] || !$this->presets->settled($preset)) {
            $changes[] = $this->settings($preset);
        }
        if ($preset->smallBusinessNote !== null && !str_contains($this->invoices->settings()->footer, $preset->smallBusinessNote)) {
            $changes[] = new PlannedChange(self::NOTE, self::KEY, Text::of('Invoices say: "%s"', $preset->smallBusinessNote), data: ['note' => $preset->smallBusinessNote]);
        }

        return $changes;
    }

    public function apply(PlannedChange $change, SetupAnswers $answers): void
    {
        $preset = $this->preset($answers);
        if ($preset === null) {
            return;
        }
        if ($change->key === self::SETTINGS) {
            $this->presets->applySettings($preset);
        } elseif ($change->key === self::NOTE && $preset->smallBusinessNote !== null) {
            $this->invoices->addFooterNote($preset->smallBusinessNote);
        } else {
            foreach ($preset->groups as $group) {
                if ($change->key === self::GROUP . $group->kind) {
                    $this->presets->applyGroup($group);
                }
            }
        }
    }

    private function preset(SetupAnswers $answers): ?TaxPreset
    {
        $tax = $answers->of(self::KEY, TaxAnswers::class);
        $country = $this->facts->country($answers);
        if ($tax === null || $country === null) {
            return null;
        }
        $selling = $this->facts->selling($answers);

        return $this->presets->preset(new TaxSetup(
            $country,
            $selling->consumers(),
            $selling->businesses(),
            $tax->registered,
            $tax->oss,
            $tax->regions,
            $tax->provider,
            $tax->ownRate,
            $tax->smallBusinessNote === null || trim($tax->smallBusinessNote) === '' ? null : trim($tax->smallBusinessNote),
        ));
    }

    private function group(PresetGroup $group, TaxPreset $preset): ?PlannedChange
    {
        $name = $this->presets->groupName($group->kind);
        $rates = array_map(static fn(SaveTaxRate $rate): array => [$rate->country, $rate->state, $rate->percent, $rate->name], $group->rates);
        $existing = $this->presets->group($group->kind);
        if ($existing !== null) {
            $now = array_map(static fn($rate): array => [$rate->country, $rate->state, $rate->percent, $rate->name], $existing->rates);
            if ($now === $rates) {
                return null;
            }
        }

        return new PlannedChange(
            self::GROUP . $group->kind,
            self::KEY,
            self::describe($name, $group, $preset),
            $existing === null ? null : Text::of('The shop has a group "%1$s" with %2$d rates; replacing it gives it these instead.', $name, \count($existing->rates)),
            ['rates' => $rates],
        );
    }

    private static function describe(string $name, PresetGroup $group, TaxPreset $preset): Text
    {
        $rates = $group->rates;
        if ($rates === []) {
            return $group->kind === PresetGroup::ZERO
                ? Text::of('A tax group "%s" for what is sold without tax.', $name)
                : Text::of('A tax group "%s"; the tax service works out its taxes.', $name);
        }
        $first = $rates[0];
        if (\count($rates) === 1) {
            return Text::of('A tax group "%1$s" at %2$s%% (%3$s).', $name, $first->percent, $first->name);
        }
        if ($first->state === null) {
            return Text::of('A tax group "%1$s": each EU country\'s own rate, %2$d rates.', $name, \count($rates));
        }
        $listed = array_map(static fn(SaveTaxRate $rate): string => "{$rate->state} {$rate->name} {$rate->percent}%", \array_slice($rates, 0, 6));

        return \count($rates) > 6
            ? Text::of('A tax group "%1$s": %2$s and %3$d more.', $name, implode(', ', $listed), \count($rates) - 6)
            : Text::of('A tax group "%1$s": %2$s.', $name, implode(', ', $listed));
    }

    private function settings(TaxPreset $preset): PlannedChange
    {
        $details = [match (TaxBasis::from($preset->basis)) {
            TaxBasis::EuOrigin => Text::of('Customers in the EU pay the shop\'s own rate, until its sales to other EU countries pass €10,000 a year.'),
            TaxBasis::EuDestination => Text::of('Consumers in the EU pay their own country\'s rate (One-Stop Shop).'),
            TaxBasis::ShopAddress => Text::of('No tax is charged.'),
            default => Text::of('Taxes go by the address the order is shipped to.'),
        }];
        if ($preset->groups !== []) {
            $details[] = $preset->pricesIncludeTax
                ? Text::of('Prices are entered with %s and shown with it.', $preset->taxName)
                : ($preset->shown === TaxSettings::WITHOUT_TAX ? Text::of('Prices are entered and shown without %s, which checkout adds.', $preset->taxName) : Text::of('Prices are entered without %s.', $preset->taxName));
        }
        if ($preset->priceSuffix !== null) {
            $details[] = Text::of('Prices in the shop say "%s".', $preset->priceSuffix);
        }
        if ($preset->reverseCharge) {
            $details[] = Text::of('Businesses in other EU countries with a valid VAT number pay none (reverse charge); checkout asks for it.');
        }
        if ($preset->shippingTaxedAsItems) {
            $details[] = Text::of('Shipping is taxed at the rates of the goods it carries.');
        }
        $current = $this->settings->current();
        $existing = $current->defaultGroupId !== null && $current->basis !== $preset->basis
            ? Text::of('Taxes go by %s now.', str_replace('_', ' ', $current->basis))
            : null;

        $summary = $preset->groups === []
            ? Text::of('The shop charges no tax (%s).', $preset->taxName)
            : Text::of('How the shop charges %1$s, at the rates in force on %2$s.', $preset->taxName, $preset->checkedOn ?? gmdate('Y-m-d'));

        return new PlannedChange(self::SETTINGS, self::KEY, $summary, $existing, [
            'basis' => $preset->basis, 'including' => $preset->pricesIncludeTax, 'reverse' => $preset->reverseCharge, 'shown' => $preset->shown,
            'suffix' => (string) $preset->priceSuffix, 'shipping' => $preset->shippingTaxedAsItems, 'provider' => $preset->provider,
        ], $preset->warnings[0] ?? null, $details);
    }
}
