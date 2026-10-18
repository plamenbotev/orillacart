<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Shipping\Application\Dto\SaveShippingClass;
use OrillaCart\Shipping\Application\Dto\ShippingClassView;
use OrillaCart\Shipping\Domain\CarrierRepository;
use OrillaCart\Shipping\Domain\Event\ShippingClassDeleted;
use OrillaCart\Shipping\Domain\ShippingClass;
use OrillaCart\Shipping\Domain\ShippingClassNotFound;
use OrillaCart\Shipping\Domain\ShippingClassRepository;
use OrillaCart\Shipping\Domain\ShippingRuleViolation;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Use cases for shipping classes, as the Shipping admin screen and the product editor run them.
 */
final readonly class ShippingClassService
{
    public function __construct(
        private ShippingClassRepository $classes,
        private CarrierRepository $carriers,
        private EventDispatcherInterface $events,
    ) {}

    /**
     * @return list<ShippingClassView> by name
     */
    public function classes(): array
    {
        return array_map(ShippingClassView::fromClass(...), $this->classes->all());
    }

    public function exists(int $id): bool
    {
        return $this->classes->find($id) !== null;
    }

    /**
     * @throws ShippingRuleViolation
     */
    public function create(SaveShippingClass $command): ShippingClassView
    {
        $this->requireFreeName($command->name, null);
        $class = new ShippingClass($command->name);
        $this->classes->save($class);

        return ShippingClassView::fromClass($class);
    }

    /**
     * @throws ShippingClassNotFound
     * @throws ShippingRuleViolation
     */
    public function rename(int $id, SaveShippingClass $command): ShippingClassView
    {
        $class = $this->get($id);
        $this->requireFreeName($command->name, $id);
        $class->rename($command->name);
        $this->classes->save($class);

        return ShippingClassView::fromClass($class);
    }

    /**
     * A class that a rate carries stays until the rate no longer lists it: removing it from the rate's list
     * could leave the list empty, and an empty list carries everything.
     *
     * @throws ShippingClassNotFound
     * @throws ShippingRuleViolation
     */
    public function delete(int $id): void
    {
        $class = $this->get($id);
        foreach ($this->carriers->all() as $carrier) {
            foreach ($carrier->rates() as $rate) {
                if (\in_array($id, $rate->details()->classes, true)) {
                    throw new ShippingRuleViolation(
                        \sprintf('The rate "%s" of %s carries "%s"; change the rate before deleting the class.', $rate->name(), $carrier->name(), $class->name()),
                        'orillacart_shipping_class_in_use',
                    );
                }
            }
        }
        $this->classes->remove($class);
        $this->events->dispatch(new ShippingClassDeleted($id));
    }

    private function get(int $id): ShippingClass
    {
        return $this->classes->find($id) ?? throw new ShippingClassNotFound($id);
    }

    private function requireFreeName(string $name, ?int $exceptId): void
    {
        if ($this->classes->isNameTaken($name, $exceptId)) {
            throw new ShippingRuleViolation(\sprintf('There is already a shipping class "%s".', trim($name)), 'orillacart_duplicate_shipping_class');
        }
    }
}
