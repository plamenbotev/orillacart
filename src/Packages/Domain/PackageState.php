<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Domain;

/** Where an extension stands: written but not yet running, running, switched off by a manager, or by the shop. */
enum PackageState: string
{
    case Draft = 'draft';
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    /** The shop switched it off after it kept failing; a manager enables it again after reading the problem. */
    case Stopped = 'stopped';

    public function runs(): bool
    {
        return $this === self::Enabled;
    }
}
