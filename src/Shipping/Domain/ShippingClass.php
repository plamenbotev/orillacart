<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A kind of goods that ships its own way, such as "Bulky" or "Fragile". A product has at most one; a rate can
 * carry only some classes.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shipping_classes')]
class ShippingClass
{
    public const int MAX_NAME_LENGTH = 100;

    /** Stands, in a rate's list of classes, for the items that have no class. */
    public const int NONE = 0;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    private string $name;

    public function __construct(string $name)
    {
        $this->rename($name);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The shipping class has no id until it is saved.');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new ShippingRuleViolation('A shipping class needs a name of up to %d characters.', 'orillacart_invalid_shipping_class_name', values: [self::MAX_NAME_LENGTH]);
        }
        $this->name = $name;
    }
}
