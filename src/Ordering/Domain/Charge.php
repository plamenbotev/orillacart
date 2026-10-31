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

    /**
     * @param list<int> $forProductIds the products it is charged for (the bottles a deposit is for): their share of
     *                                 it goes back with them; none for the whole order
     */
    public function __construct(
        public string $source,
        public bool $taxable = true,
        public ?int $taxGroupId = null,
        public array $forProductIds = [],
    ) {}

    /**
     * How many of the charge go back with these items, out of those it was charged for: its share of them, whole.
     *
     * @param int $back  how many of the products it is for go back
     * @param int $of    how many of them the order holds
     * @param int $count how many of the charge the order holds
     */
    public static function share(int $back, int $of, int $count): int
    {
        return $of < 1 ? 0 : min($count, intdiv($back * $count, $of));
    }

    /** The shop's own, which the shop may take off again. */
    public function isShops(): bool
    {
        return $this->source === self::SHOP;
    }
}
