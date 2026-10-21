<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\GiftCards;

use OrillaCart\GiftCards\Domain\GiftCard;
use OrillaCart\GiftCards\Domain\GiftCardCode;
use OrillaCart\GiftCards\Domain\GiftCardEntry;
use OrillaCart\GiftCards\Domain\GiftCardKind;
use OrillaCart\GiftCards\Domain\GiftCardRepository;
use OrillaCart\Tests\Fixtures\ImmediateTransactions;

final class InMemoryGiftCards implements GiftCardRepository
{
    /** @var array<int, GiftCard> */
    public array $cards = [];

    /** @var list<GiftCardEntry> */
    public array $entries = [];

    private int $nextId = 1;
    private int $nextEntry = 1;

    public function __construct(private readonly ?ImmediateTransactions $transactions = null) {}

    public function find(int $id, bool $lock = false): ?GiftCard
    {
        $this->locking($lock);

        return $this->cards[$id] ?? null;
    }

    public function withCode(GiftCardCode $code, bool $lock = false): ?GiftCard
    {
        $this->locking($lock);
        $hash = new \ReflectionProperty(GiftCard::class, 'codeHash');

        return array_find($this->cards, static fn(GiftCard $card): bool => $hash->getValue($card) === $code->hash());
    }

    public function storeCreditOf(int $customerId, bool $lock = false): ?GiftCard
    {
        $this->locking($lock);

        return array_find($this->cards, static fn(GiftCard $card): bool => $card->kind() === GiftCardKind::StoreCredit && $card->customerId() === $customerId);
    }

    public function page(string $search, int $page, int $perPage): array
    {
        $found = array_values(array_filter(array_reverse($this->cards), static fn(GiftCard $card): bool => $card->kind() === GiftCardKind::GiftCard && ($search === '' || str_contains($card->recipientEmail(), $search) || $card->codeEnd() === strtoupper($search))));

        return ['cards' => \array_slice($found, ($page - 1) * $perPage, $perPage), 'total' => \count($found)];
    }

    public function boughtWith(int $orderId): array
    {
        return array_values(array_filter($this->cards, static fn(GiftCard $card): bool => $card->orderId() === $orderId));
    }

    public function toRecipient(string $email): array
    {
        return array_values(array_filter($this->cards, static fn(GiftCard $card): bool => strcasecmp($card->recipientEmail(), trim($email)) === 0));
    }

    public function save(GiftCard $card): void
    {
        $id = new \ReflectionProperty(GiftCard::class, 'id');
        if ($id->getValue($card) === null) {
            $id->setValue($card, $this->nextId++);
        }
        $this->cards[$card->id()] = $card;
    }

    public function add(GiftCardEntry $entry): void
    {
        new \ReflectionProperty(GiftCardEntry::class, 'id')->setValue($entry, $this->nextEntry++);
        $this->entries[] = $entry;
    }

    public function entries(int $cardId): array
    {
        return array_values(array_filter($this->entries, static fn(GiftCardEntry $entry): bool => $entry->cardId() === $cardId));
    }

    public function entriesOfOrder(int $orderId): array
    {
        return array_values(array_filter($this->entries, static fn(GiftCardEntry $entry): bool => $entry->orderId() === $orderId));
    }

    private function locking(bool $lock): void
    {
        if ($lock) {
            $this->transactions?->requireOpen();
        }
    }
}
