<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Extension;

use OrillaCart\Extension\Registry;

/**
 * Something inside the plugin that registers on the add-on registry as a PHP add-on would: the scripted
 * extensions (design 56). Run when the registry collects, before the `orillacart_extensions` action, each on
 * its own: one that throws is logged and the rest still register. Tagged by the kernel.
 */
interface Registrant
{
    public const string TAG = 'orillacart.extension_registrant';

    public function register(Registry $registry): void;
}
