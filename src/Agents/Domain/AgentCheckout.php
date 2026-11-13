<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A shopper's agent's checkout session (UCP, design 75): one of the shop's carts, which the shop prices as its own
 * checkout does, with the buyer and the address the agent gave and the way to ship it chose. The buyer pays at the
 * shop's checkout, through the session's link: the order placed from the cart completes it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'agent_checkouts')]
#[ORM\Index(name: 'agent_checkout_cart', columns: ['cart_id'])]
#[ORM\Index(name: 'agent_checkout_token', columns: ['token_hash'])]
#[ORM\Index(name: 'agent_checkout_expires', columns: ['expires_at'])]
class AgentCheckout
{
    public const string INCOMPLETE = 'incomplete';
    public const string REQUIRES_ESCALATION = 'requires_escalation';
    public const string READY_FOR_COMPLETE = 'ready_for_complete';
    public const string COMPLETE_IN_PROGRESS = 'complete_in_progress';
    public const string COMPLETED = 'completed';
    public const string CANCELED = 'canceled';
    /** How long a session lasts: UCP's default. */
    public const string LASTS = 'PT6H';
    public const string ID_PATTERN = 'chk_[a-f0-9]{32}';

    #[ORM\Column(length: 30)]
    private string $status = self::INCOMPLETE;

    /** @var array{email?: string, first_name?: string, last_name?: string, phone_number?: string} */
    #[ORM\Column(type: 'sealed_json')]
    private array $buyer = [];

    /** @var array<string, string>|null where it ships, as UCP writes an address */
    #[ORM\Column(type: 'sealed_json', nullable: true)]
    private ?array $address = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $shippingOption = null;

    #[ORM\Column(nullable: true)]
    private ?int $orderId = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 40)]
        private string $id,
        #[ORM\Column]
        private int $cartId,
        #[\SensitiveParameter]
        #[ORM\Column(type: 'sealed_string', length: 255)]
        private string $cartToken,
        /** The agent's profile it named (UCP-Agent). */
        #[ORM\Column(length: 500)]
        private string $agent,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        if (preg_match('/^' . self::ID_PATTERN . '$/', $id) !== 1) {
            throw new \InvalidArgumentException('A checkout session id is chk_ and 32 hex digits.');
        }
        $this->tokenHash = self::hash($cartToken);
        $this->updatedAt = $createdAt;
        $this->expiresAt = $createdAt->add(new \DateInterval(self::LASTS));
    }

    public static function newId(): string
    {
        return 'chk_' . bin2hex(random_bytes(16));
    }

    /** How a cart's token is found without keeping it readable: the browser's cart at checkout. */
    public static function hash(#[\SensitiveParameter] string $cartToken): string
    {
        return hash('sha256', $cartToken);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function cartId(): int
    {
        return $this->cartId;
    }

    public function cartToken(): string
    {
        return $this->cartToken;
    }

    public function agent(): string
    {
        return $this->agent;
    }

    public function status(\DateTimeImmutable $now): string
    {
        return $this->expiresAt <= $now && !$this->isFinal() ? self::CANCELED : $this->status;
    }

    public function isFinal(): bool
    {
        return \in_array($this->status, [self::COMPLETED, self::CANCELED], true);
    }

    /** @return array{email?: string, first_name?: string, last_name?: string, phone_number?: string} */
    public function buyer(): array
    {
        return $this->buyer;
    }

    /** @return array<string, string>|null */
    public function address(): ?array
    {
        return $this->address;
    }

    public function shippingOption(): ?string
    {
        return $this->shippingOption;
    }

    public function orderId(): ?int
    {
        return $this->orderId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * What the agent set: the buyer, where it ships, the way chosen.
     *
     * @param array{email?: string, first_name?: string, last_name?: string, phone_number?: string} $buyer
     * @param array<string, string>|null                                                              $address
     *
     * @throws \LogicException for a session that is done
     */
    public function set(array $buyer, ?array $address, ?string $shippingOption, \DateTimeImmutable $now): void
    {
        $this->open($now);
        $this->buyer = $buyer;
        $this->address = $address;
        $this->shippingOption = $shippingOption;
        $this->updatedAt = $now;
    }

    /** Worked out from what it holds, each time it is read or changed. */
    public function become(string $status, \DateTimeImmutable $now): void
    {
        if (!$this->isFinal()) {
            $this->status = $status;
            $this->updatedAt = $now;
        }
    }

    /** The order the buyer placed from its cart, at the shop's checkout. */
    public function ordered(int $orderId, \DateTimeImmutable $now): void
    {
        $this->orderId = $orderId;
        $this->status = self::COMPLETED;
        $this->updatedAt = $now;
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        $this->open($now);
        $this->status = self::CANCELED;
        $this->updatedAt = $now;
    }

    private function open(\DateTimeImmutable $now): void
    {
        if ($this->isFinal() || $this->expiresAt <= $now) {
            throw new \LogicException('The checkout session is ' . $this->status($now) . '.');
        }
    }
}
