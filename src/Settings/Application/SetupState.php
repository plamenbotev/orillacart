<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Application;

/** Whether the shop manager went through the setup (or chose not to): until then, the admin offers it. */
interface SetupState
{
    public function finished(): bool;

    public function finish(): void;
}
