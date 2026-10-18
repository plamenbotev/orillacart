<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Search\Domain\EngineSettings;

/**
 * An external search server the shop can switch to.
 */
interface SearchServer
{
    /**
     * Reaches the server with the settings and prepares the index (made, with its fields, when missing).
     *
     * @return string|null what went wrong; null when the server is ready to be filled
     */
    public function prepare(EngineSettings $settings): ?string;
}
