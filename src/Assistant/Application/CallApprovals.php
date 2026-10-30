<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application;

use OrillaCart\Assistant\Application\Dto\CallApproval;
use OrillaCart\Assistant\Application\Dto\Caller;

/**
 * The owner's yes for clients that cannot ask it (MCP revisions before 2026-07-28): the call waits on an
 * approval page in wp-admin, where its owner, logged in, sees what it would do and answers; the same call, made
 * again, then runs once.
 */
interface CallApprovals
{
    /** The approval of this caller's call, while it lasts. */
    public function find(Caller $caller, string $call): ?CallApproval;

    public function byToken(string $token): ?CallApproval;

    /** Puts the call up for the owner's answer, for $seconds. */
    public function ask(Caller $caller, string $call, string $title, string $risk, string $what, int $seconds): CallApproval;

    public function answer(CallApproval $approval, bool $yes): void;

    /**
     * The call runs, or is refused: its approval is spent. True for the one call that spent it; false when another
     * did first (two retries at once), which must then not run.
     */
    public function spend(CallApproval $approval): bool;

    /** Where its owner answers. */
    public function url(CallApproval $approval): string;
}
