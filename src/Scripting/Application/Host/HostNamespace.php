<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application\Host;

use OrillaCart\Scripting\Application\Dto\HostFunction;

/**
 * A group of functions the host offers scripts (`time`, `http`, `shop.orders`). Each area of the shop adds its own
 * as a service tagged TAG (the kernel tags every implementation); the catalogue gathers them. A new function is a PHP change, versioned with the add-on API.
 */
interface HostNamespace
{
    public const string TAG = 'orillacart.script_host';

    /** @return list<HostFunction> */
    public function functions(): array;
}
