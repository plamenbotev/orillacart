<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application;

/**
 * What the assistant's server says about the shop when a client connects.
 */
interface AssistantContext
{
    /** The shop's name, as the site calls itself. */
    public function shopName(): string;

    /** The plugin's version. */
    public function version(): string;

    /** Guidance for the model: whose shop this is, its currency, how amounts and changes work. */
    public function instructions(): string;
}
