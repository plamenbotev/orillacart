<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

use Psr\Container\ContainerInterface;

/**
 * The network's other shops, for what concerns a network user on every site: their account is the network's, and
 * each shop keeps what it knows of them in its own tables. Nothing on a single site.
 */
interface OtherShops
{
    /**
     * Runs the step as each other site of the network that runs the shop, with that site's container; a shop
     * whose tables are behind the files' build is brought up first.
     *
     * @param \Closure(ContainerInterface): void $step
     */
    public function each(\Closure $step): void;
}
