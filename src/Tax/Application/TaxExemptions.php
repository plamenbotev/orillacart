<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Customers\Application\Accounts;
use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Reports\Application\ShopCalendar;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tax\Application\Dto\SaveTaxExemption;
use OrillaCart\Tax\Application\Dto\TaxExemptionView;
use OrillaCart\Tax\Domain\ExemptionReason;
use OrillaCart\Tax\Domain\TaxExemption;
use OrillaCart\Tax\Domain\TaxExemptionRepository;
use OrillaCart\Tax\Domain\TaxLocation;
use Psr\Clock\ClockInterface;

/**
 * Customers' exemptions from tax: resellers, nonprofits and governments buying with a certificate the shop keeps
 * on file. Taxes ask which exemption, if any, covers a sale.
 */
final readonly class TaxExemptions
{
    public function __construct(
        private TaxExemptionRepository $exemptions,
        private Accounts $accounts,
        private ClockInterface $clock,
        private ShopCalendar $calendar,
    ) {}

    /** @return list<TaxExemptionView> newest first */
    public function of(int $customerId): array
    {
        return array_map(TaxExemptionView::fromExemption(...), $this->exemptions->ofCustomer($customerId));
    }

    /**
     * @throws CustomerNotFound
     * @throws RuleViolation for a certificate, region or note that cannot be one
     */
    public function add(int $customerId, SaveTaxExemption $command): TaxExemptionView
    {
        $this->accounts->find($customerId) ?? throw new CustomerNotFound($customerId);
        $exemption = new TaxExemption(
            $customerId,
            ExemptionReason::from($command->reason),
            $command->certificate,
            array_values(array_map(strtoupper(...), $command->regions)),
            $command->expiresOn === null ? null : new \DateTimeImmutable($command->expiresOn),
            $command->note,
            $this->clock->now(),
        );
        $this->exemptions->save($exemption);

        return TaxExemptionView::fromExemption($exemption);
    }

    /**
     * @throws RuleViolation for an exemption that is not the customer's
     */
    public function remove(int $customerId, int $exemptionId): void
    {
        $exemption = $this->exemptions->find($exemptionId);
        if ($exemption === null || $exemption->customerId() !== $customerId) {
            throw new RuleViolation('The customer has no such exemption.', 'orillacart_unknown_exemption');
        }
        $this->exemptions->remove($exemption);
    }

    /**
     * The exemption covering a customer's sale taxed at the address today, the shop's day: the newest that holds
     * there. Its last day ends at the shop's midnight, not at UTC's.
     */
    public function covering(?int $customerId, TaxLocation $location): ?TaxExemptionView
    {
        if ($customerId === null) {
            return null;
        }
        $today = $this->clock->now()->setTimezone($this->calendar->timezone());
        foreach ($this->exemptions->ofCustomer($customerId) as $exemption) {
            if ($exemption->covers($location, $today)) {
                return TaxExemptionView::fromExemption($exemption);
            }
        }

        return null;
    }

    public function forgetCustomer(int $customerId): void
    {
        $this->exemptions->removeOfCustomer($customerId);
    }
}
