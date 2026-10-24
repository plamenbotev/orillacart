<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\References;

/**
 * A customer's request to send some of an order's items back, and where it stands: the shop approves or refuses
 * it, says when the items came back, and refunds them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'returns')]
#[ORM\Index(name: 'return_order', columns: ['order_id'])]
#[ORM\Index(name: 'return_status', columns: ['status'])]
class ItemReturn
{
    public const int MAX_COMMENT_LENGTH = 1000;
    public const int MAX_ANSWER_LENGTH = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ReturnStatus::class)]
    private ReturnStatus $status = ReturnStatus::Requested;

    /** @var list<array{line: int, quantity: int}> the order's lines it sends back, by position */
    #[ORM\Column(type: 'json')]
    private array $items = [];

    #[ORM\Column(type: 'text')]
    private string $comment;

    /** What the shop told the customer when it answered: how to send the items, or why not. */
    #[ORM\Column(type: 'text')]
    private string $answer = '';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $answeredAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $receivedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

    /** The refund of its items, while the provider sends the money. */
    #[ORM\Column(nullable: true)]
    #[References('refunds', onDelete: 'SET NULL')]
    private ?int $refundId = null;

    /**
     * @param array<int, int> $items quantities by line position
     *
     * @throws ReturnRuleViolation for nothing to send back, or a comment too long
     */
    public function __construct(
        #[ORM\Column]
        #[References('orders', onDelete: 'CASCADE')]
        private int $orderId,
        array $items,
        #[ORM\Column(length: 20, enumType: ReturnReason::class)]
        private ReturnReason $reason,
        string $comment,
        #[ORM\Column]
        private \DateTimeImmutable $requestedAt,
    ) {
        foreach ($items as $line => $quantity) {
            if ($quantity > 0) {
                $this->items[] = ['line' => $line, 'quantity' => $quantity];
            }
        }
        if ($this->items === []) {
            throw new ReturnRuleViolation('Choose at least one item to send back.', 'orillacart_invalid_return_items');
        }
        $this->comment = trim($comment);
        if (mb_strlen($this->comment) > self::MAX_COMMENT_LENGTH) {
            throw new ReturnRuleViolation('Say why in up to %d characters.', 'orillacart_invalid_return_comment', values: [self::MAX_COMMENT_LENGTH]);
        }
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The return has no id until it is saved.');
    }

    public function orderId(): int
    {
        return $this->orderId;
    }

    public function status(): ReturnStatus
    {
        return $this->status;
    }

    /** @return array<int, int> quantities by line position */
    public function items(): array
    {
        return array_column($this->items, 'quantity', 'line');
    }

    public function reason(): ReturnReason
    {
        return $this->reason;
    }

    public function comment(): string
    {
        return $this->comment;
    }

    public function answer(): string
    {
        return $this->answer;
    }

    public function requestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function answeredAt(): ?\DateTimeImmutable
    {
        return $this->answeredAt;
    }

    public function receivedAt(): ?\DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function refundedAt(): ?\DateTimeImmutable
    {
        return $this->refundedAt;
    }

    /**
     * @param string $answer what the customer reads: where to send the items, and how
     *
     * @throws ReturnRuleViolation for a return answered already
     */
    public function approve(string $answer, \DateTimeImmutable $at): void
    {
        $this->answered(ReturnStatus::Approved, $answer, $at);
    }

    /**
     * @param string $answer why, for the customer
     *
     * @throws ReturnRuleViolation for a return answered already, or no reason given
     */
    public function refuse(string $answer, \DateTimeImmutable $at): void
    {
        if (trim($answer) === '') {
            throw new ReturnRuleViolation('Tell the customer why the return is refused.', 'orillacart_invalid_return_answer');
        }
        $this->answered(ReturnStatus::Refused, $answer, $at);
    }

    /**
     * The items came back. A return the shop never answered is approved by taking them.
     *
     * @throws ReturnRuleViolation for one refused, or received already
     */
    public function receive(\DateTimeImmutable $at): void
    {
        if ($this->status !== ReturnStatus::Requested && $this->status !== ReturnStatus::Approved) {
            throw new ReturnRuleViolation('Return %1$d is %2$s; only a requested or approved one is received.', 'orillacart_return_not_receivable', values: [$this->id(), $this->status->value]);
        }
        $this->status = ReturnStatus::Received;
        $this->answeredAt ??= $at;
        $this->receivedAt = $at;
    }

    /**
     * @throws ReturnRuleViolation for one whose items did not come back
     */
    public function refunded(\DateTimeImmutable $at): void
    {
        if ($this->status !== ReturnStatus::Received && $this->status !== ReturnStatus::Refunding) {
            throw new ReturnRuleViolation('Return %1$d is %2$s; its items are refunded once they came back.', 'orillacart_return_not_refundable', values: [$this->id(), $this->status->value]);
        }
        $this->status = ReturnStatus::Refunded;
        $this->refundedAt = $at;
    }

    /**
     * The refund was made, and the provider sends the money later.
     *
     * @throws ReturnRuleViolation for one whose items did not come back
     */
    public function refunding(int $refundId): void
    {
        if ($this->status !== ReturnStatus::Received) {
            throw new ReturnRuleViolation('Return %1$d is %2$s; its items are refunded once they came back.', 'orillacart_return_not_refundable', values: [$this->id(), $this->status->value]);
        }
        $this->status = ReturnStatus::Refunding;
        $this->refundId = $refundId;
    }

    public function refundId(): ?int
    {
        return $this->refundId;
    }

    /** The provider gave up on the refund: the items are back, the money is not, and the shop refunds again. */
    public function refundFailed(): void
    {
        if ($this->status === ReturnStatus::Refunding) {
            $this->status = ReturnStatus::Received;
            $this->refundId = null;
        }
    }

    private function answered(ReturnStatus $status, string $answer, \DateTimeImmutable $at): void
    {
        if ($this->status !== ReturnStatus::Requested) {
            throw new ReturnRuleViolation('Return %d was answered already.', 'orillacart_return_answered', values: [$this->id()]);
        }
        $answer = trim($answer);
        if (mb_strlen($answer) > self::MAX_ANSWER_LENGTH) {
            throw new ReturnRuleViolation('Answer in up to %d characters.', 'orillacart_invalid_return_answer', values: [self::MAX_ANSWER_LENGTH]);
        }
        $this->status = $status;
        $this->answer = $answer;
        $this->answeredAt = $at;
    }
}
