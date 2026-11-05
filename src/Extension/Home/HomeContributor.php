<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Home;

/**
 * What an add-on puts on the Home screen (Shop → Home): figures of its own among the shop's, and lines in the
 * "Needs you" list for what waits on the shop manager. Asked each time the screen opens, so keep it quick: a
 * count from a table, not a call to a provider.
 */
interface HomeContributor
{
    /** Lowercase letters, digits and underscores, up to 40; unique among contributors. */
    public function id(): string;

    /** @return list<HomeTile> */
    public function tiles(): array;

    /** @return list<HomeLine> */
    public function needsYou(): array;
}
