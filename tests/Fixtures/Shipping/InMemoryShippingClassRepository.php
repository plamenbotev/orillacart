<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Shipping;

use OrillaCart\Shipping\Domain\ShippingClass;
use OrillaCart\Shipping\Domain\ShippingClassRepository;

/**
 * Keeps shipping classes in memory and numbers them on save, as the database does.
 */
final class InMemoryShippingClassRepository implements ShippingClassRepository
{
    /** @var array<int, ShippingClass> */
    private array $classes = [];
    private int $nextId = 1;

    public function all(): array
    {
        $classes = array_values($this->classes);
        usort($classes, static fn(ShippingClass $a, ShippingClass $b): int => [$a->name(), $a->id()] <=> [$b->name(), $b->id()]);

        return $classes;
    }

    public function find(int $id): ?ShippingClass
    {
        return $this->classes[$id] ?? null;
    }

    public function isNameTaken(string $name, ?int $exceptId = null): bool
    {
        foreach ($this->classes as $id => $class) {
            if ($id !== $exceptId && $class->name() === trim($name)) {
                return true;
            }
        }

        return false;
    }

    public function save(ShippingClass $class): void
    {
        $id = new \ReflectionProperty(ShippingClass::class, 'id');
        if ($id->getValue($class) === null) {
            $id->setValue($class, $this->nextId++);
        }
        $this->classes[$class->id()] = $class;
    }

    public function remove(ShippingClass $class): void
    {
        unset($this->classes[$class->id()]);
    }
}
