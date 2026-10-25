<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Domain;

/**
 * A part of where the shop sells, priced on its own: the shop's country, the rest of the EU, the rest of
 * Europe, the rest of the world, or the other countries listed.
 */
final readonly class ShippingZone
{
    public const string HOME = 'home';
    public const string EU = 'eu';
    public const string EUROPE = 'europe';
    public const string WORLD = 'world';
    public const string LISTED = 'listed';

    /**
     * @param list<string> $countries where it goes; empty for everywhere but those excluded
     * @param list<string> $excluded  the countries of the zones before it, for the rest of the world
     */
    public function __construct(
        public string $id,
        public array $countries,
        public array $excluded = [],
    ) {}

    /**
     * The zones of a shop in a country selling to these regions.
     *
     * @param list<string> $eu     the EU's countries
     * @param list<string> $listed the countries listed, for Regions::Listed
     *
     * @return list<self>
     */
    public static function of(string $home, Regions $regions, array $eu, array $listed = []): array
    {
        $zones = [new self(self::HOME, [$home])];
        $covered = [$home];
        $inEu = \in_array($home, $eu, true);
        if ($regions === Regions::Listed) {
            $others = array_values(array_diff($listed, $covered));

            return $others === [] ? $zones : [...$zones, new self(self::LISTED, $others)];
        }
        if (\in_array($regions, [Regions::Eu, Regions::Europe], true) || ($regions === Regions::World && $inEu)) {
            $zones[] = new self(self::EU, array_values(array_diff($eu, $covered)));
            $covered = array_values(array_unique([...$covered, ...$eu]));
        }
        if ($regions === Regions::Europe) {
            $zones[] = new self(self::EUROPE, array_values(array_diff(Regions::EUROPE, $covered)));
        }
        if ($regions === Regions::World) {
            $zones[] = new self(self::WORLD, [], $covered);
        }

        return $zones;
    }
}
