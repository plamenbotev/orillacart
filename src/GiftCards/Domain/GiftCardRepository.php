<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Domain;

interface GiftCardRepository
{
    public function find(int $id, bool $lock = false): ?GiftCard;

    public function withCode(GiftCardCode $code, bool $lock = false): ?GiftCard;

    public function storeCreditOf(int $customerId, bool $lock = false): ?GiftCard;

    /**
     * @return array{cards: list<GiftCard>, total: int} gift cards, newest first; those whose recipient or code end
     *                                                   matches the search
     */
    public function page(string $search, int $page, int $perPage): array;

    /** @return list<GiftCard> those bought with the order */
    public function boughtWith(int $orderId): array;

    /** @return list<GiftCard> those made out to the email, in any case */
    public function toRecipient(string $email): array;

    public function save(GiftCard $card): void;

    public function add(GiftCardEntry $entry): void;

    /** @return list<GiftCardEntry> oldest first */
    public function entries(int $cardId): array;

    /** @return list<GiftCardEntry> those of the order, oldest first */
    public function entriesOfOrder(int $orderId): array;
}
