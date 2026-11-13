<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

/**
 * Who, outside the shop's staff, a run is for (design 74): its failures count towards stopping the extension at
 * most once an hour each, so a visitor who finds the input a route or a form breaks on cannot switch the
 * extension off for every customer by sending it ten times. A failure that real traffic meets is still met by
 * ten customers in a row, and stops it as before.
 */
interface AnonymousCaller
{
    /**
     * @return string|null "user:7" for a customer, "ip:…" for a visitor; null for a manager, a background job, the
     *                     command line or a scheduled task, whose failures all count
     */
    public function who(): ?string;
}
