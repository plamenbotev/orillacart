<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

/**
 * What a charge line is besides its price: who charges it ("add_on:<type>", "shop"), and how it is taxed: as items of
 * the tax group are (the shop's default for none), or not.
 */
final readonly class Charge
{
    public const string SHOP = 'shop';

    public function __construct(
        public string $source,
        public bool $taxable = true,
        public ?int $taxGroupId = null,
    ) {}

    /** The shop's own, which the shop may take off again. */
    public function isShops(): bool
    {
        return $this->source === self::SHOP;
    }
}
