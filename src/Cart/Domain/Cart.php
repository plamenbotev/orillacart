<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a customer means to buy. A cart does not hold stock: checkout takes it when the order is placed.
 * The browser keeps the cart's token in a cookie; the database keeps only its hash, so a copy of the
 * database does not give access to anyone's cart.
 */
#[ORM\Entity]
#[ORM\Table(name: 'carts')]
#[ORM\UniqueConstraint(name: 'cart_token', columns: ['token_hash'])]
#[ORM\Index(name: 'cart_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'cart_updated', columns: ['updated_at'])]
#[ORM\Index(name: 'cart_checkout', columns: ['checkout_at'])]
#[ORM\UniqueConstraint(name: 'cart_recover', columns: ['recover_hash'])]
class Cart
{
    public const int MAX_LINES = 100;
    public const int MAX_QUANTITY = 9999;
    /** How long an order counts as being placed from the cart: longer than a payment can take to start. */
    public const string PLACING_FOR = 'PT2M';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    /** @var Collection<int, CartLine> */
    #[ORM\OneToMany(targetEntity: CartLine::class, mappedBy: 'cart', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var list<string> coupon codes, as entered; checkout decides which hold */
    #[ORM\Column(type: 'json')]
    private array $coupons = [];

    /** @var list<string> gift card codes, as entered; checkout decides what each pays */
    #[ORM\Column(type: 'json')]
    private array $giftCards = [];

    /** The customer pays with their store credit. */
    #[ORM\Column(options: ['default' => false])]
    private bool $useStoreCredit = false;

    /** The order being placed from the cart while its payment starts: checkout places no second one meanwhile. */
    #[ORM\Column(nullable: true)]
    private ?int $placingOrderId = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $placingSince = null;

    /** The email given at checkout, kept only while its owner asks to be reminded of the cart. */
    #[ORM\Column(length: 254, nullable: true)]
    private ?string $contactEmail = null;

    /** Whether the customer asked, at checkout, to be reminded of the cart if they leave it. */
    #[ORM\Column(options: ['default' => false])]
    private bool $remind = false;

    /** The language the customer was at checkout in, for the reminder. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $locale = null;

    /** When the customer was last at checkout with the cart; null for a cart that never got there. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $checkoutAt = null;

    /** The abandonment the cart is in, from when it sat at checkout long enough until an order is placed from it. */
    #[ORM\Column(nullable: true)]
    private ?int $abandonedId = null;

    /** The hash of the token in the reminder's link, which brings a visitor's cart back on another browser. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $recoverHash = null;

    public function __construct(
        string $token,
        #[ORM\Column(nullable: true)]
        private ?int $customerId,
        \DateTimeImmutable $now,
    ) {
        $this->tokenHash = self::hash($token);
        $this->lines = new ArrayCollection();
        $this->updatedAt = $now;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The cart has no id until it is saved.');
    }

    public function customerId(): ?int
    {
        return $this->customerId;
    }

    /** A new token for the cart, for a customer who comes back on another device: the old cookie stops working. */
    public function rekey(string $token): void
    {
        $this->tokenHash = self::hash($token);
    }

    /** A customer who logged in keeps the cart they filled as a visitor. */
    public function belongTo(int $customerId): void
    {
        $this->customerId ??= $customerId;
    }

    /** @return list<CartLine> in the order they were added */
    public function lines(): array
    {
        $lines = $this->lines->toArray();
        usort($lines, static fn(CartLine $a, CartLine $b): int => $a->position() <=> $b->position());

        return $lines;
    }

    public function isEmpty(): bool
    {
        return $this->lines->isEmpty();
    }

    public function line(int $id): CartLine
    {
        foreach ($this->lines as $line) {
            if ($line->isSaved() && $line->id() === $id) {
                return $line;
            }
        }

        throw new CartLineNotFound($id);
    }

    /**
     * The line that holds the product with these options (and bundle choices, and answers), if any.
     *
     * @param list<int>             $optionIds
     * @param list<int>             $bundleChoices
     * @param array<string, string> $answers       to the product's forms, by field id
     */
    public function lineFor(int $productId, array $optionIds, array $bundleChoices = [], array $answers = []): ?CartLine
    {
        foreach ($this->lines as $line) {
            if ($line->isFor($productId, $optionIds, $bundleChoices, $answers)) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Adds items; the same product with the same options (and bundle choices, and answers) adds to its line.
     *
     * @param list<int>             $optionIds
     * @param list<int>             $bundleChoices
     * @param array<string, string> $answers       to the product's forms, by field id, as checked
     *
     * @return CartLine the line now holding them
     */
    public function add(int $productId, array $optionIds, int $quantity, \DateTimeImmutable $now, array $bundleChoices = [], array $answers = []): CartLine
    {
        foreach ($this->lines as $line) {
            if ($line->isFor($productId, $optionIds, $bundleChoices, $answers)) {
                $line->setQuantity($line->quantity() + $quantity);
                $this->updatedAt = $now;

                return $line;
            }
        }
        if ($this->lines->count() >= self::MAX_LINES) {
            throw new CartRuleViolation('A cart holds up to %d different items.', 'orillacart_cart_full', values: [self::MAX_LINES]);
        }
        $position = 0;
        foreach ($this->lines as $line) {
            $position = max($position, $line->position() + 1);
        }
        $line = new CartLine($this, $productId, $optionIds, $quantity, $position, $bundleChoices, $answers);
        $this->lines->add($line);
        $this->updatedAt = $now;

        return $line;
    }

    /**
     * @throws CartLineNotFound
     */
    public function changeQuantity(int $lineId, int $quantity, \DateTimeImmutable $now): void
    {
        $this->line($lineId)->setQuantity($quantity);
        $this->updatedAt = $now;
    }

    /**
     * @throws CartLineNotFound
     */
    public function remove(int $lineId, \DateTimeImmutable $now): void
    {
        $this->lines->removeElement($this->line($lineId));
        $this->updatedAt = $now;
    }

    /** @return list<string> */
    public function coupons(): array
    {
        return $this->coupons;
    }

    /**
     * @param list<string> $codes
     */
    public function useCoupons(array $codes, \DateTimeImmutable $now): void
    {
        $this->coupons = \array_slice(array_values(array_unique($codes)), 0, 10);
        $this->updatedAt = $now;
    }

    /** @return list<string> */
    public function giftCards(): array
    {
        return $this->giftCards;
    }

    /**
     * @param list<string> $codes
     */
    public function useGiftCards(array $codes, \DateTimeImmutable $now): void
    {
        $this->giftCards = \array_slice(array_values(array_unique($codes)), 0, 5);
        $this->updatedAt = $now;
    }

    public function usesStoreCredit(): bool
    {
        return $this->useStoreCredit;
    }

    public function useStoreCredit(bool $use, \DateTimeImmutable $now): void
    {
        $this->useStoreCredit = $use;
        $this->updatedAt = $now;
    }

    public function clear(\DateTimeImmutable $now): void
    {
        $this->coupons = [];
        $this->giftCards = [];
        $this->useStoreCredit = false;
        $this->lines->clear();
        $this->updatedAt = $now;
        $this->notPlacing();
        $this->contactEmail = null;
        $this->remind = false;
        $this->checkoutAt = null;
        $this->abandonedId = null;
        $this->recoverHash = null;
    }

    /**
     * The customer is at checkout with the cart: the email they gave, and whether they ask to be reminded. Without
     * the ask, the email is not kept.
     */
    public function atCheckout(?string $email, bool $remind, ?string $locale, \DateTimeImmutable $now): void
    {
        $this->remind = $remind && $email !== null;
        $this->contactEmail = $this->remind ? $email : null;
        $this->locale = $locale;
        $this->checkoutAt = $now;
    }

    public function checkoutAt(): ?\DateTimeImmutable
    {
        return $this->checkoutAt;
    }

    public function contactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function remind(): bool
    {
        return $this->remind;
    }

    public function locale(): ?string
    {
        return $this->locale;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** The cart sat at checkout long enough: it is in this abandonment until it is ordered. */
    public function abandon(int $abandonedId): void
    {
        $this->abandonedId = $abandonedId;
    }

    public function abandonedId(): ?int
    {
        return $this->abandonedId;
    }

    /** A new token for the reminder's link; the one before stops working. */
    public function recoverWith(string $token): void
    {
        $this->recoverHash = self::hash($token);
    }

    /** Forgets the email given at checkout, as a privacy request asks. */
    public function forgetContact(): void
    {
        $this->contactEmail = null;
        $this->remind = false;
    }

    /**
     * Adds what another cart holds, as far as this one has room: the same item adds to its line, up to
     * MAX_QUANTITY; new lines up to MAX_LINES. Codes join, up to ten.
     */
    public function takeFrom(self $other, \DateTimeImmutable $now): void
    {
        foreach ($other->lines() as $line) {
            $mine = $this->lineFor($line->productId(), $line->optionIds(), $line->bundleChoices(), $line->answers());
            if ($mine !== null) {
                $mine->setQuantity(min(self::MAX_QUANTITY, $mine->quantity() + $line->quantity()));
            } elseif ($this->lines->count() < self::MAX_LINES) {
                $this->add($line->productId(), $line->optionIds(), $line->quantity(), $now, $line->bundleChoices(), $line->answers());
            }
        }
        $this->useCoupons([...$this->coupons, ...$other->coupons()], $now);
        $this->useGiftCards([...$this->giftCards, ...$other->giftCards()], $now);
    }

    /** An order was saved from the cart; its payment is about to start. */
    public function placing(int $orderId, \DateTimeImmutable $now): void
    {
        $this->placingOrderId = $orderId;
        $this->placingSince = $now;
    }

    /**
     * The order being placed from the cart, if any. One marked longer ago than PLACING_FOR is not: the request
     * placing it ended without saying how, and the cart must not stay stuck.
     */
    public function orderBeingPlaced(\DateTimeImmutable $now): ?int
    {
        if ($this->placingSince === null || $this->placingSince <= $now->sub(new \DateInterval(self::PLACING_FOR))) {
            return null;
        }

        return $this->placingOrderId;
    }

    /** The order's payment could not start: the cart is the customer's again, as it was. */
    public function notPlacing(): void
    {
        $this->placingOrderId = null;
        $this->placingSince = null;
    }
}
