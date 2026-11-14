<?php

declare(strict_types=1);

namespace OrillaCart\Reports\Application;

/**
 * How many extensions were stopped after failing, for the Home screen to ask the shop to look. Packages answers.
 */
interface StoppedExtensions
{
    public function stopped(): int;
}
