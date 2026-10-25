<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application;

use OrillaCart\Setup\Application\Dto\Readiness;

/**
 * One thing the shop needs before it sells, or is advised to have: a way to pay, shipping to where it sells,
 * the pages the law asks for. Tagged by the Kernel, so the container finds every one.
 */
interface ReadinessCheck
{
    public const string TAG = 'orillacart.readiness_check';

    /** @return list<Readiness> what is missing; empty when all is well */
    public function check(): array;
}
