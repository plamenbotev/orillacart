<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Infrastructure\Events;

use OrillaCart\Customers\Domain\Event\CustomerDeleted;
use OrillaCart\Customers\Domain\Event\CustomerErased;
use OrillaCart\Tax\Application\TaxExemptions;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A deleted or erased customer's exemptions go with them; the orders they covered keep what they recorded.
 */
final readonly class ForgetExemptionsOfDeletedCustomers
{
    public function __construct(private TaxExemptions $exemptions) {}

    #[AsEventListener]
    public function __invoke(CustomerDeleted $event): void
    {
        $this->exemptions->forgetCustomer($event->customerId);
    }

    #[AsEventListener]
    public function erased(CustomerErased $event): void
    {
        $this->exemptions->forgetCustomer($event->customerId);
    }
}
