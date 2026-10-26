<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application;

/**
 * Every source of tools the container found.
 */
interface ToolSources
{
    /** @return list<ToolSource> */
    public function all(): array;
}
