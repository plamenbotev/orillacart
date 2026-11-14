<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application;

/**
 * History a module keeps for as long as the shop's "Keep history for" setting says (design 55). Each module
 * forgets its own; the daily task gives the moment before which it goes.
 */
interface KeptHistory
{
    public const string TAG = 'orillacart.kept_history';

    public function forgetBefore(\DateTimeImmutable $before): void;
}
