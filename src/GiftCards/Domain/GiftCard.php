<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\Text;

/**
 * Money the shop owes someone, to spend in it: a gift card, which whoever has its code pays with, or a customer's
 * store credit, which they use from their account. Only the SHA-256 of a code is kept, and its last four
 * characters to tell cards apart. The balance changes only by entries, under a lock, so two orders never spend
 * the same money.
 */
#[ORM\Entity]
#[ORM\Table(name: 'gift_cards')]
#[ORM\UniqueConstraint(name: 'gift_card_code', columns: ['code_hash'])]
#[ORM\Index(name: 'gift_cards_of_customer', columns: ['customer_id', 'kind'])]
#[ORM\Index(name: 'gift_cards_of_order', columns: ['order_id'])]
class GiftCard
{
    public const int MAX_NAME_LENGTH = 100;
    public const int MAX_MESSAGE_LENGTH = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $codeHash;

    #[ORM\Column(length: 4)]
    private string $codeEnd;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $initial;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $balance;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    private string $recipientName;

    #[ORM\Column(length: 254)]
    private string $recipientEmail;

    #[ORM\Column(length: self::MAX_MESSAGE_LENGTH)]
    private string $message;

    private function __construct(
        #[ORM\Column(length: 20, enumType: GiftCardKind::class)]
        private GiftCardKind $kind,
        #[ORM\Column(length: 3, options: ['fixed' => true])]
        private string $currency,
        /** Whose store credit; for a gift card, nobody's. */
        #[ORM\Column(nullable: true)]
        private ?int $customerId,
        /** The order it was bought with; null for one the shop gave. */
        #[ORM\Column(nullable: true)]
        private ?int $orderId,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {}

    /**
     * Only the code's hash is kept.
     *
     * @throws GiftCardRuleViolation
     */
    public static function issue(GiftCardCode $code, Money $amount, string $currency, \DateTimeImmutable $now, ?\DateTimeImmutable $expiresAt = null, string $recipientName = '', string $recipientEmail = '', string $message = '', ?int $orderId = null): self
    {
        if ($amount->isZero() || $amount->isNegative()) {
            throw new GiftCardRuleViolation('A gift card is worth more than nothing.', 'orillacart_invalid_gift_card_amount');
        }
        if ($expiresAt !== null && $expiresAt <= $now) {
            throw new GiftCardRuleViolation('A gift card expires after it is made.', 'orillacart_invalid_gift_card_expiry');
        }
        if ($recipientEmail !== '' && filter_var($recipientEmail, \FILTER_VALIDATE_EMAIL) === false) {
            throw new GiftCardRuleViolation('Enter the email address the gift card goes to.', 'orillacart_invalid_gift_card_email');
        }
        $card = new self(GiftCardKind::GiftCard, $currency, null, $orderId, $now);
        $card->codeHash = $code->hash();
        $card->codeEnd = $code->end();
        $card->initial = $amount->toFixed();
        $card->balance = $amount->toFixed();
        $card->expiresAt = $expiresAt;
        $card->recipientName = mb_substr(trim($recipientName), 0, self::MAX_NAME_LENGTH);
        $card->recipientEmail = trim($recipientEmail);
        $card->message = mb_substr(trim($message), 0, self::MAX_MESSAGE_LENGTH);

        return $card;
    }

    /** A customer's store credit, empty until the shop adds to it. */
    public static function storeCredit(int $customerId, string $currency, \DateTimeImmutable $now): self
    {
        $card = new self(GiftCardKind::StoreCredit, $currency, $customerId, null, $now);
        $card->codeHash = null;
        $card->codeEnd = '';
        $card->initial = Money::zero()->toFixed();
        $card->balance = Money::zero()->toFixed();
        $card->expiresAt = null;
        $card->recipientName = '';
        $card->recipientEmail = '';
        $card->message = '';

        return $card;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The gift card has no id until it is saved.');
    }

    public function kind(): GiftCardKind
    {
        return $this->kind;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function customerId(): ?int
    {
        return $this->customerId;
    }

    public function orderId(): ?int
    {
        return $this->orderId;
    }

    public function balance(): Money
    {
        return Money::of($this->balance);
    }

    public function initial(): Money
    {
        return Money::of($this->initial);
    }

    public function codeEnd(): string
    {
        return $this->codeEnd;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function recipientName(): string
    {
        return $this->recipientName;
    }

    public function recipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** How the customer sees it on the order: "Gift card ····7KQ2" or "Store credit". */
    public function label(): Text
    {
        return $this->kind === GiftCardKind::StoreCredit ? new Text('Store credit') : Text::of('Gift card ····%s', $this->codeEnd);
    }

    /**
     * Why it cannot pay for an order in the currency now, in words for the customer; null when it can.
     */
    public function refusal(string $currency, \DateTimeImmutable $now): ?Text
    {
        return match (true) {
            !$this->active => new Text('This gift card is not valid.'),
            $this->expiresAt !== null && $now >= $this->expiresAt => new Text('This gift card has expired.'),
            $this->currency !== $currency => Text::of('This gift card is in %1$s and cannot pay for an order in %2$s.', $this->currency, $currency),
            $this->balance()->isZero() => new Text('This gift card has been used up.'),
            default => null,
        };
    }

    /**
     * Takes up to the amount off the balance; what it took, which the entry records.
     */
    public function spend(Money $amount): Money
    {
        $taken = $amount->compare($this->balance()) > 0 ? $this->balance() : $amount->atLeastZero();
        $this->balance = $this->balance()->subtract($taken)->toFixed();

        return $taken;
    }

    /**
     * Adds to the balance (money given back, or more credit); a negative amount takes off, never below nothing.
     *
     * @throws GiftCardRuleViolation when it would take off more than is left
     */
    public function credit(Money $amount): void
    {
        $balance = $this->balance()->add($amount);
        if ($balance->isNegative()) {
            throw new GiftCardRuleViolation('Only %1$s %2$s is left to take off.', 'orillacart_gift_card_balance', values: [$this->balance(), $this->currency]);
        }
        $this->balance = $balance->toFixed();
    }

    /** The code was lost or given to the wrong person: a new one replaces it, and the old one pays no more. */
    public function replaceCode(GiftCardCode $code): void
    {
        if ($this->kind === GiftCardKind::StoreCredit) {
            throw new GiftCardRuleViolation('Store credit has no code.', 'orillacart_store_credit_code');
        }
        $this->codeHash = $code->hash();
        $this->codeEnd = $code->end();
    }

    /** A privacy request erased the recipient: the card, and what is left on it, stays. */
    public function forgetRecipient(): void
    {
        $this->recipientName = '';
        $this->recipientEmail = '';
        $this->message = '';
    }

    /**
     * @throws GiftCardRuleViolation
     */
    public function change(bool $active, ?\DateTimeImmutable $expiresAt): void
    {
        if ($this->kind === GiftCardKind::StoreCredit && $expiresAt !== null) {
            throw new GiftCardRuleViolation('Store credit does not expire.', 'orillacart_invalid_gift_card_expiry');
        }
        $this->active = $active;
        $this->expiresAt = $expiresAt;
    }
}
