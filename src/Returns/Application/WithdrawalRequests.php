<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application;

use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Returns\Application\Dto\AskToWithdraw;
use OrillaCart\Returns\Application\Dto\ConfirmWithdrawal;
use OrillaCart\Returns\Application\Dto\DeclareWithdrawal;
use OrillaCart\Returns\Application\Dto\FindWithdrawableOrder;
use OrillaCart\Returns\Application\Dto\OpenWithdrawal;
use OrillaCart\Returns\Application\Dto\WithdrawableOrderView;
use OrillaCart\Returns\Application\Dto\WithdrawalView;
use OrillaCart\Returns\Domain\ReturnRuleViolation;
use OrillaCart\Returns\Domain\WithdrawalOrderNotFound;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Security\Signatures;
use Psr\Clock\ClockInterface;

/**
 * The withdrawal function as anyone reaches it. Asked by an order's number and email, the shop answers the same
 * whatever they are (an order or none, open or closed), and emails the order's own address a link: whoever has
 * the order's mail sees its items and withdraws, nobody else. The withdrawal counts as declared when it was asked
 * for, so the time the link takes to arrive does not count against the withdrawal period. A link works for
 * VALID_DAYS, as often as needed while items are left.
 */
final readonly class WithdrawalRequests
{
    /** The job that emails the link (SendWithdrawalLink). */
    public const string LINK_JOB = 'mail.withdrawal_link';
    public const int VALID_DAYS = 7;
    private const string SIGNATURE = 'withdrawal-link';

    public function __construct(
        private Returns $returns,
        private OrderService $orders,
        private Signatures $signatures,
        private Jobs $jobs,
        private ClockInterface $clock,
    ) {}

    /**
     * Emails the order's address the link to withdraw, when the number and email are an order's that is open.
     *
     * @param (\Closure(int): bool)|null $mayMail asked, with the order's number, before a link goes: whether its
     *                                          address may have one more today, so asking is no way to flood it
     */
    public function ask(AskToWithdraw $command, ?\Closure $mayMail = null): void
    {
        try {
            $this->returns->withdrawable(new FindWithdrawableOrder($command->number, $command->email));
            $order = $this->orders->order($command->number);
        } catch (WithdrawalOrderNotFound|ReturnRuleViolation|OrderNotFound) {
            return;
        }
        if ($mayMail !== null && !$mayMail($order->number)) {
            return;
        }
        $askedAt = $this->clock->now()->getTimestamp();
        $this->jobs->push(self::LINK_JOB, ['order' => $order->number, 'token' => $this->token($order->number, $order->email, $askedAt)], "withdrawal:{$order->number}");
    }

    /**
     * What of the order the link opens is left to withdraw from.
     *
     * @throws NotFound            for a link that does not work (any more)
     * @throws ReturnRuleViolation     for an order closed since
     */
    public function withdrawable(OpenWithdrawal $command): WithdrawableOrderView
    {
        [$number, $email] = $this->open($command->token);

        return $this->returns->withdrawable(new FindWithdrawableOrder($number, $email));
    }

    /**
     * Records the withdrawal from the order the link opens, as declared when the link was asked for.
     *
     * @throws NotFound            for a link that does not work (any more)
     * @throws ReturnRuleViolation     for an order closed since, or items it does not have left
     */
    public function withdraw(ConfirmWithdrawal $command): WithdrawalView
    {
        [$number, $email, $askedAt] = $this->open($command->token);

        return $this->returns->withdraw(new DeclareWithdrawal($number, $email, $command->name, $command->confirmationEmail, $command->items, $askedAt));
    }

    /** The token of an order's link, signed for its number, its email and when it was asked for. */
    public function token(int $number, string $email, int $askedAt): string
    {
        return "{$number}.{$askedAt}." . $this->signatures->sign(self::SIGNATURE, self::signed($number, $email, $askedAt));
    }

    /**
     * @return array{int, string, \DateTimeImmutable} the order's number and email, and when the link was asked for
     *
     * @throws NotFound for a link that is not one the shop made, or is too old
     */
    private function open(#[\SensitiveParameter] string $token): array
    {
        $parts = explode('.', $token, 3);
        if (\count($parts) !== 3 || preg_match('/^\d{1,18}$/', $parts[0]) !== 1 || preg_match('/^\d{1,12}$/', $parts[1]) !== 1) {
            throw self::badLink();
        }
        [$number, $askedAt] = [(int) $parts[0], (int) $parts[1]];
        $age = $this->clock->now()->getTimestamp() - $askedAt;
        if ($age < 0 || $age > self::VALID_DAYS * 86400) {
            throw self::badLink();
        }
        try {
            $email = $this->orders->order($number)->email;
        } catch (OrderNotFound) {
            throw self::badLink();
        }
        if (!$this->signatures->verify(self::SIGNATURE, self::signed($number, $email, $askedAt), $parts[2])) {
            throw self::badLink();
        }

        return [$number, $email, new \DateTimeImmutable('@' . $askedAt)];
    }

    private static function badLink(): NotFound
    {
        return new NotFound('This link does not work any more: ask for a new one on the withdrawal page.', 'orillacart_withdrawal_link_invalid');
    }

    private static function signed(int $number, string $email, int $askedAt): string
    {
        return $number . ':' . mb_strtolower(trim($email)) . ':' . $askedAt;
    }
}
