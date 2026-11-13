<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Application;

/** The profile an agent names in UCP-Agent, fetched safely and kept a while. */
interface PlatformProfiles
{
    /**
     * @return array<string, mixed> the profile's `ucp` member
     *
     * @throws UcpError 424 profile_unreachable, 422 profile_malformed
     */
    public function fetch(string $url): array;
}
