<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

interface NexusThresholdsRepository
{
    /** The saved thresholds, or the defaults when nothing valid was saved. */
    public function load(): NexusThresholds;

    public function save(NexusThresholds $thresholds): void;
}
