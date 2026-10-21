<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application;

/**
 * A few sample products, to see the shop working before adding its own: added from the setup wizard or
 * `wp orillacart sample-data`, and taken away again, with only what they brought.
 */
interface SampleData
{
    /** Whether the sample products are in the shop. */
    public function present(): bool;

    /**
     * @return int how many products were added; none when they are there already
     */
    public function add(): int;

    /**
     * @return int how many products went
     */
    public function remove(): int;
}
