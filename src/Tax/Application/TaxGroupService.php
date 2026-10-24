<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Shared\Domain\PostcodePattern;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tax\Application\Dto\SaveTaxGroup;
use OrillaCart\Tax\Application\Dto\SaveTaxRate;
use OrillaCart\Tax\Application\Dto\TaxGroupView;
use OrillaCart\Tax\Application\Dto\TaxRateView;
use OrillaCart\Tax\Domain\EuStandardRates;
use OrillaCart\Tax\Domain\Event\TaxRatesChanged;
use OrillaCart\Tax\Domain\Percentage;
use OrillaCart\Tax\Domain\TaxGroup;
use OrillaCart\Tax\Domain\TaxGroupNotFound;
use OrillaCart\Tax\Domain\TaxGroupRepository;
use OrillaCart\Tax\Domain\TaxRateDetails;
use OrillaCart\Tax\Domain\TaxRateNotFound;
use OrillaCart\Tax\Domain\TaxSettingsRepository;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Use cases for tax groups and their rates, as the Tax admin screen runs them.
 */
final readonly class TaxGroupService
{
    public function __construct(
        private TaxGroupRepository $groups,
        private TaxSettingsRepository $settings,
        private GeographyService $geography,
        private EventDispatcherInterface $events,
        private ?TaxGroupUses $uses = null,
    ) {}

    /**
     * @return list<TaxGroupView> by name
     */
    public function groups(): array
    {
        return array_map(TaxGroupView::fromGroup(...), $this->groups->all());
    }

    /**
     * @throws TaxGroupNotFound
     */
    public function group(int $id): TaxGroupView
    {
        return TaxGroupView::fromGroup($this->get($id));
    }

    public function exists(int $id): bool
    {
        return $this->groups->find($id) !== null;
    }

    public function create(SaveTaxGroup $command): TaxGroupView
    {
        $group = new TaxGroup($command->name);
        $group->changeTaxCode($command->taxCode);
        $this->groups->save($group);

        return TaxGroupView::fromGroup($group);
    }

    /**
     * @throws TaxGroupNotFound
     */
    public function rename(int $id, SaveTaxGroup $command): TaxGroupView
    {
        $group = $this->get($id);
        $group->rename($command->name);
        $group->changeTaxCode($command->taxCode);
        $this->groups->save($group);

        return TaxGroupView::fromGroup($group);
    }

    /**
     * @throws TaxGroupNotFound
     * @throws RuleViolation   for the shop's default group, or one products or shipping use
     */
    public function delete(int $id): void
    {
        $group = $this->get($id);
        if ($this->settings->load()->defaultGroupId === $id) {
            throw new RuleViolation(
                '"%s" is the default tax group; choose another default before deleting it.',
                'orillacart_tax_group_in_use',
                values: [$group->name()],
            );
        }
        $using = $this->uses?->of($id) ?? [];
        if ($using !== []) {
            throw new RuleViolation(
                '"%1$s" taxes %2$s: choose another tax group for them before deleting it, or they would be taxed at the default group\'s rates without anyone choosing it.',
                'orillacart_tax_group_in_use',
                values: [$group->name(), implode(', ', $using)],
            );
        }
        $this->groups->remove($group);
        $this->events->dispatch(new TaxRatesChanged($id));
    }

    /**
     * @throws TaxGroupNotFound
     * @throws RuleViolation    for a location that does not exist
     */
    public function addRate(int $groupId, SaveTaxRate $command): TaxRateView
    {
        $group = $this->get($groupId);
        $rate = $group->addRate($this->details($command));
        $this->groups->save($group);
        $this->events->dispatch(new TaxRatesChanged($groupId));

        return TaxRateView::fromRate($rate);
    }

    /**
     * Adds the standard VAT rate of every EU country the group has no rate for yet, to charge EU customers
     * their own rate (OSS). Rates the group has are kept.
     *
     * @return int how many were added
     *
     * @throws TaxGroupNotFound
     */
    public function addEuStandardRates(int $groupId): int
    {
        $group = $this->get($groupId);
        $covered = [];
        foreach ($group->rates() as $rate) {
            $details = $rate->details();
            if ($details->state === null) {
                $covered[$details->country] = true;
            }
        }
        $added = 0;
        foreach (EuStandardRates::RATES as $country => $percent) {
            if (isset($covered[$country]) || $this->geography->locate($country, null) === null) {
                continue;
            }
            $group->addRate(new TaxRateDetails($country, null, Percentage::of($percent), 'VAT', 0));
            ++$added;
        }
        if ($added > 0) {
            $this->groups->save($group);
            $this->events->dispatch(new TaxRatesChanged($groupId));
        }

        return $added;
    }

    /**
     * @throws TaxGroupNotFound
     * @throws TaxRateNotFound
     * @throws RuleViolation                         for a location that does not exist
     */
    public function changeRate(int $groupId, int $rateId, SaveTaxRate $command): TaxRateView
    {
        $group = $this->get($groupId);
        $rate = $group->changeRate($rateId, $this->details($command));
        $this->groups->save($group);
        $this->events->dispatch(new TaxRatesChanged($groupId));

        return TaxRateView::fromRate($rate);
    }

    /**
     * @throws TaxGroupNotFound
     * @throws TaxRateNotFound
     */
    public function removeRate(int $groupId, int $rateId): void
    {
        $group = $this->get($groupId);
        $group->removeRate($rateId);
        $this->groups->save($group);
        $this->events->dispatch(new TaxRatesChanged($groupId));
    }

    private function get(int $id): TaxGroup
    {
        return $this->groups->find($id) ?? throw new TaxGroupNotFound($id);
    }

    private function details(SaveTaxRate $command): TaxRateDetails
    {
        $location = $this->geography->require($command->country, $command->state);

        $postcodes = array_values(array_filter(array_map(trim(...), $command->postcodes), static fn(string $postcode): bool => $postcode !== ''));
        $cities = array_values(array_unique(array_filter(array_map(TaxRateDetails::city(...), $command->cities), static fn(string $city): bool => $city !== '')));

        return new TaxRateDetails($location->country, $location->state, Percentage::of($command->percent), trim($command->name), $command->priority, array_map(PostcodePattern::of(...), $postcodes), $command->compound, $command->code === null || trim($command->code) === '' ? null : trim($command->code), $cities, $command->shipping);
    }
}
