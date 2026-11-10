<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

/** The addresses of the site itself, where a scripted route may send its caller without declaring a host. */
interface RedirectTargets
{
    /** The URL made safe to send, when it is a path of the site or an address on one of its own hosts; null otherwise. */
    public function sameSite(string $url): ?string;
}
