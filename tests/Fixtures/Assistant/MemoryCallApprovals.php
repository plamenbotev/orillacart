<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Assistant;

use OrillaCart\Assistant\Application\CallApprovals;
use OrillaCart\Assistant\Application\Dto\CallApproval;
use OrillaCart\Assistant\Application\Dto\Caller;

/**
 * The approval page in memory: a test answers for the owner.
 */
final class MemoryCallApprovals implements CallApprovals
{
    /** @var array<string, CallApproval> by token */
    public array $approvals = [];

    public function find(Caller $caller, string $call): ?CallApproval
    {
        foreach ($this->approvals as $approval) {
            if ($approval->userId === $caller->userId && $approval->keyId === $caller->keyId && $approval->call === $call) {
                return $approval;
            }
        }

        return null;
    }

    public function byToken(string $token): ?CallApproval
    {
        return $this->approvals[$token] ?? null;
    }

    public function ask(Caller $caller, string $call, string $title, string $risk, string $what, int $seconds): CallApproval
    {
        $token = str_pad((string) (\count($this->approvals) + 1), 32, '0', STR_PAD_LEFT);

        return $this->approvals[$token] = new CallApproval($token, $caller->userId, $caller->keyId, $call, $title, $risk, $what, CallApproval::WAITING, time() + $seconds);
    }

    public function answer(CallApproval $approval, bool $yes): void
    {
        $this->approvals[$approval->token] = $approval->answered($yes);
    }

    public function spend(CallApproval $approval): void
    {
        unset($this->approvals[$approval->token]);
    }

    public function url(CallApproval $approval): string
    {
        return "https://shop.test/wp-admin/admin.php?page=orillacart-approve&request={$approval->token}";
    }

    /** The owner answers the one waiting approval. */
    public function answerWaiting(bool $yes): void
    {
        foreach ($this->approvals as $approval) {
            if ($approval->state === CallApproval::WAITING) {
                $this->answer($approval, $yes);
            }
        }
    }
}
