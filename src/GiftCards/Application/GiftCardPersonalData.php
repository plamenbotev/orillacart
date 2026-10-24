<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application;

use OrillaCart\Customers\Application\PersonalDataSource;
use OrillaCart\GiftCards\Domain\GiftCardRepository;

/** The gift cards made out to a person: who for, the message, and what is left on them. */
final readonly class GiftCardPersonalData implements PersonalDataSource
{
    public function __construct(private GiftCardRepository $cards) {}

    public function personalData(string $email, ?int $customerId): array
    {
        $items = [];
        foreach (trim($email) === '' ? [] : $this->cards->toRecipient($email) as $card) {
            $items[] = ['group' => 'orillacart-gift-cards', 'label' => 'Gift cards', 'id' => 'orillacart-gift-card-' . $card->id(), 'data' => array_values(array_filter([
                ['name' => 'Card', 'value' => '····' . $card->codeEnd()],
                ['name' => 'For', 'value' => $card->recipientName()],
                $card->message() === '' ? null : ['name' => 'Message', 'value' => $card->message()],
                ['name' => 'Left', 'value' => $card->balance() . ' ' . $card->currency()],
            ]))];
        }

        return $items;
    }
}
