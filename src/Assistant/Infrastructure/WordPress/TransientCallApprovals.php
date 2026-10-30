<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\WordPress;

use OrillaCart\Assistant\Application\CallApprovals;
use OrillaCart\Assistant\Application\Dto\CallApproval;
use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Infrastructure\WordPress\Hooks\CallApprovalPage;

/**
 * Approvals kept as transients for as long as they last: one by its token, for the page, and one pointing to it
 * by the caller and the call, for the call made again. An approval lost early (an evicted object cache) is asked
 * again, never taken as a yes. Spending one is claimed in the database first, so of two retries sent at once only
 * one runs.
 */
final readonly class TransientCallApprovals implements CallApprovals
{
    public function __construct(private OnceClaims $claims) {}

    private const string BY_TOKEN = 'orillacart_mcp_approval_';
    private const string BY_CALL = 'orillacart_mcp_approval_call_';

    public function find(Caller $caller, string $call): ?CallApproval
    {
        $token = get_transient(self::BY_CALL . self::callKey($caller->userId, $caller->keyId, $call));
        $approval = \is_string($token) ? $this->byToken($token) : null;

        return $approval !== null && $approval->userId === $caller->userId && $approval->keyId === $caller->keyId && $approval->call === $call ? $approval : null;
    }

    public function byToken(string $token): ?CallApproval
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }
        $data = get_transient(self::BY_TOKEN . $token);
        if (!\is_array($data) || !\is_int($data['user'] ?? null) || !\is_int($data['expires'] ?? null) || $data['expires'] < time()) {
            return null;
        }

        return new CallApproval(
            $token,
            $data['user'],
            \is_int($data['key'] ?? null) ? $data['key'] : null,
            self::text($data, 'call'),
            self::text($data, 'title'),
            self::text($data, 'risk'),
            self::text($data, 'what'),
            self::text($data, 'state'),
            $data['expires'],
        );
    }

    public function ask(Caller $caller, string $call, string $title, string $risk, string $what, int $seconds): CallApproval
    {
        $approval = new CallApproval(bin2hex(random_bytes(16)), $caller->userId, $caller->keyId, $call, $title, $risk, $what, CallApproval::WAITING, time() + $seconds);
        $this->save($approval);
        set_transient(self::BY_CALL . self::callKey($caller->userId, $caller->keyId, $call), $approval->token, $seconds);

        return $approval;
    }

    public function answer(CallApproval $approval, bool $yes): void
    {
        $this->save($approval->answered($yes));
    }

    public function spend(CallApproval $approval): bool
    {
        $claimed = $this->claims->claim('mcp-approval|' . $approval->token, $approval->expiresAt + 1);
        delete_transient(self::BY_TOKEN . $approval->token);
        delete_transient(self::BY_CALL . self::callKey($approval->userId, $approval->keyId, $approval->call));

        return $claimed;
    }

    public function url(CallApproval $approval): string
    {
        return add_query_arg(['page' => CallApprovalPage::PAGE, 'request' => $approval->token], admin_url('admin.php'));
    }

    private function save(CallApproval $approval): void
    {
        set_transient(self::BY_TOKEN . $approval->token, [
            'user' => $approval->userId,
            'key' => $approval->keyId,
            'call' => $approval->call,
            'title' => $approval->title,
            'risk' => $approval->risk,
            'what' => $approval->what,
            'state' => $approval->state,
            'expires' => $approval->expiresAt,
        ], max(1, $approval->expiresAt - time()));
    }

    private static function callKey(int $userId, ?int $keyId, string $call): string
    {
        return hash('sha256', $userId . '|' . ($keyId ?? '-') . '|' . $call);
    }

    /**
     * @param array<mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        return \is_string($data[$key] ?? null) ? $data[$key] : '';
    }
}
