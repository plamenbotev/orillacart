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
    /** How the cart remembers the add-on types whose lines the shopper removed, among the discounts. */
    public const string ADD_ON = 'add_on:';
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

    /** @var list<string> the discounts, by code, whose items the shopper removed: not given again while they hold */
    #[ORM\Column(type: 'json')]
    private array $declined = [];

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

    /**
     * What the cart holds and when it last changed, in one string: checkout places an order from the cart it read,
     * and from no other (a cart changed, or emptied by the order placed from it, since).
     */
    public function fingerprint(): string
    {
        // As the row keeps them: the time to the second, and the answers' keys in one order whichever way they
        // were read, so a cart read again from the database is the same cart.
        return hash('sha256', json_encode([
            array_map(static function (CartLine $line): array {
                $answers = $line->answers();
                ksort($answers, SORT_STRING);

                return [$line->type(), $line->givenBy(), $line instanceof AddOnLine ? $line->addOnType() : null, $line->productId(), $line->optionIds(), $line->bundleChoices(), $line->quantity(), $answers];
            }, $this->lines()),
            $this->coupons,
            $this->giftCards,
            $this->useStoreCredit,
            $this->updatedAt->format('Y-m-d H:i:s'),
        ], JSON_THROW_ON_ERROR));
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
        $line = new ProductLine($this, $productId, $optionIds, $quantity, $this->nextPosition(), $bundleChoices, $answers);
        $this->lines->add($line);
        $this->updatedAt = $now;

        return $line;
    }

    private function nextPosition(): int
    {
        $position = 0;
        foreach ($this->lines as $line) {
            $position = max($position, $line->position() + 1);
        }

        return $position;
    }

    /**
     * @throws CartLineNotFound
     * @throws CartRuleViolation for an item given: how many is the discount's
     */
    public function changeQuantity(int $lineId, int $quantity, \DateTimeImmutable $now): void
    {
        $line = $this->line($lineId);
        if (!$line->quantityChangeable() && $quantity !== $line->quantity()) {
            throw new CartRuleViolation('How many of a free item you get is the offer\'s: change the items it comes with.', 'orillacart_given_quantity');
        }
        $line->setQuantity($quantity);
        $this->updatedAt = $now;
    }

    /**
     * Removes the line. An item given is not given again while its discount holds, nor an add-on's while it keeps
     * it: the shopper did not want it.
     *
     * @throws CartLineNotFound
     * @throws CartRuleViolation for an add-on's line it does not let the shopper remove
     */
    public function remove(int $lineId, \DateTimeImmutable $now): void
    {
        $line = $this->line($lineId);
        if (!$line->removable()) {
            throw new CartRuleViolation('This item comes with others in your cart: remove those instead.', 'orillacart_kept_line');
        }
        $declined = $line instanceof AddOnLine ? self::ADD_ON . $line->addOnType() : $line->givenBy();
        if ($declined !== null) {
            $this->declined = array_values(array_unique([...$this->declined, $declined]));
        }
        $this->lines->removeElement($line);
        $this->updatedAt = $now;
    }

    /**
     * Keeps the items discounts give in step with what they give now: adds those missing, changes how many, and
     * removes those no longer given. A discount whose items the shopper removed gives none until it stops holding,
     * when the cart forgets they declined it. New lines go in while the cart has room.
     *
     * @param list<GivenItem> $items what the discounts give now
     *
     * @return bool whether the cart changed
     */
    public function keepGiven(array $items, \DateTimeImmutable $now): bool
    {
        $codes = array_map(static fn(GivenItem $item): string => $item->code, $items);
        $declined = array_values(array_filter($this->declined, static fn(string $code): bool => str_starts_with($code, self::ADD_ON) || \in_array($code, $codes, true)));
        $changed = $declined !== $this->declined;
        $this->declined = $declined;
        $items = array_values(array_filter($items, static fn(GivenItem $item): bool => !\in_array($item->code, $declined, true)));
        foreach ($this->lines() as $line) {
            if (!$line instanceof GivenLine) {
                continue;
            }
            $item = array_find($items, static fn(GivenItem $item): bool => $line->isAs($item));
            if ($item === null) {
                $this->lines->removeElement($line);
                $changed = true;
            } elseif ($item->quantity !== $line->quantity() || $item->label !== $line->label()) {
                $line->giveAs($item);
                $changed = true;
            }
        }
        foreach ($items as $item) {
            $have = array_any($this->lines(), static fn(CartLine $line): bool => $line instanceof GivenLine && $line->isAs($item));
            if (!$have && $this->lines->count() < self::MAX_LINES) {
                $this->lines->add(new GivenLine($this, $item->productId, $item->optionIds, $item->quantity, $this->nextPosition(), $item->bundleChoices, mb_substr($item->code, 0, 40), mb_substr($item->label, 0, 255)));
                $changed = true;
            }
        }
        if ($changed) {
            $this->updatedAt = $now;
        }

        return $changed;
    }

    /**
     * Keeps an add-on's lines in step with what its cart line type keeps now (CartLineType::lines()), as
     * keepGiven() does. A type whose lines the shopper removed keeps none until it keeps nothing, or they ask.
     *
     * @param list<array{productId: int, optionIds: list<int>, quantity: int}> $items
     *
     * @return bool whether the cart changed
     */
    public function keepAddOn(string $type, string $label, bool $removable, array $items, \DateTimeImmutable $now): bool
    {
        $key = self::ADD_ON . $type;
        $changed = false;
        if ($items === [] && \in_array($key, $this->declined, true)) {
            $this->declined = array_values(array_filter($this->declined, static fn(string $declined): bool => $declined !== $key));
            $changed = true;
        }
        if (\in_array($key, $this->declined, true)) {
            $items = [];
        }
        foreach ($this->lines() as $line) {
            if (!$line instanceof AddOnLine || $line->addOnType() !== $type) {
                continue;
            }
            $item = array_find($items, static fn(array $item): bool => $line->isOf($item['productId'], $item['optionIds']));
            if ($item === null) {
                $this->lines->removeElement($line);
                $changed = true;
            } elseif ($item['quantity'] !== $line->quantity() || $label !== $line->label() || $removable !== $line->removable()) {
                $line->keepAs($item['quantity'], $label, $removable);
                $changed = true;
            }
        }
        foreach ($items as $item) {
            $have = array_any($this->lines(), static fn(CartLine $line): bool => $line instanceof AddOnLine && $line->addOnType() === $type && $line->isOf($item['productId'], $item['optionIds']));
            if (!$have && $this->lines->count() < self::MAX_LINES) {
                $this->lines->add(new AddOnLine($this, $item['productId'], $item['optionIds'], $item['quantity'], $this->nextPosition(), mb_substr($type, 0, 40), mb_substr($label, 0, 100), $removable));
                $changed = true;
            }
        }
        if ($changed) {
            $this->updatedAt = $now;
        }

        return $changed;
    }

    /** @return list<string> the discounts whose items the shopper removed, by code; add-ons' as "add_on:<type>" */
    public function declined(): array
    {
        return $this->declined;
    }

    /** The shopper wants the discount's items after all: they are given at the next keepGiven(). */
    public function undecline(string $code, \DateTimeImmutable $now): void
    {
        $this->declined = array_values(array_filter($this->declined, static fn(string $declined): bool => $declined !== $code));
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
        $this->declined = [];
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
     * MAX_QUANTITY; new lines up to MAX_LINES, and the files they name up to $maxFiles in all. Codes join, up to ten.
     */
    public function takeFrom(self $other, \DateTimeImmutable $now, int $maxFiles): void
    {
        $files = \count($this->fileHashes());
        foreach ($other->lines() as $line) {
            if (!$line instanceof ProductLine) {
                // Given, or kept, again as the cart's discounts and add-ons keep them.
                continue;
            }
            $mine = $this->lineFor($line->productId(), $line->optionIds(), $line->bundleChoices(), $line->answers());
            $named = \count(self::filesOf($line->answers()));
            if ($mine !== null) {
                $mine->setQuantity(min(self::MAX_QUANTITY, $mine->quantity() + $line->quantity()));
            } elseif ($this->lines->count() < self::MAX_LINES && $files + $named <= $maxFiles) {
                $this->add($line->productId(), $line->optionIds(), $line->quantity(), $now, $line->bundleChoices(), $line->answers());
                $files += $named;
            }
        }
        $this->useCoupons([...$this->coupons, ...$other->coupons()], $now);
        $this->useGiftCards([...$this->giftCards, ...$other->giftCards()], $now);
    }

    /**
     * The files the lines name, by their tokens' hashes as answers keep them ("#<hash>").
     *
     * @return list<string>
     */
    public function fileHashes(): array
    {
        $hashes = [];
        foreach ($this->lines() as $line) {
            array_push($hashes, ...self::filesOf($line->answers()));
        }

        return $hashes;
    }

    /**
     * @param array<string, string> $answers
     *
     * @return list<string>
     */
    private static function filesOf(array $answers): array
    {
        $hashes = [];
        foreach ($answers as $value) {
            if (preg_match('/^#([a-f0-9]{64})$/', $value, $match) === 1) {
                $hashes[] = $match[1];
            }
        }

        return $hashes;
    }

    /** The order being placed from the cart, however long ago; null when none is. */
    public function placingOrderId(): ?int
    {
        return $this->placingOrderId;
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
