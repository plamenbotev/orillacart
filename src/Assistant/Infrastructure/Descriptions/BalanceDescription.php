<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\Descriptions;

use OrillaCart\Assistant\Application\CallDescription;
use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\GiftCards\Application\Dto\GiftCardDetailView;
use OrillaCart\GiftCards\Application\GiftCardService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\NotFound;

/**
 * A gift card's or a customer's store credit changed by hand: whose, from what to what.
 */
final readonly class BalanceDescription implements CallDescription
{
    public function __construct(private GiftCardService $giftCards) {}

    public function describe(ToolDefinition $tool, array $arguments, Caller $caller): ?string
    {
        $id = Words::int($arguments, 'id');
        if ($id === null || !\in_array($tool->name, ['adjust_gift_card', 'adjust_store_credit'], true)) {
            return null;
        }
        try {
            $detail = $tool->name === 'adjust_gift_card' ? $this->giftCards->card($id) : $this->giftCards->storeCredit($id);
            $amount = Words::string($arguments, 'amount');
            $after = is_numeric($amount) ? (string) Money::of($detail->card->balance)->add(Money::of($amount)) : '?';
            $note = Words::string($arguments, 'note');

            return \sprintf(
                '%s %s %s: its balance goes from %s to %s %s.%s',
                str_starts_with($amount, '-') ? 'Takes' : 'Adds',
                ltrim($amount, '-+') . ' ' . $detail->card->currency,
                str_starts_with($amount, '-') ? 'off ' . self::whose($detail, $tool->name) : 'to ' . self::whose($detail, $tool->name),
                $detail->card->balance,
                $after,
                $detail->card->currency,
                $note === '' ? '' : "\nNote: " . Words::quoted($note),
            );
        } catch (NotFound) {
            return null;
        }
    }

    private static function whose(GiftCardDetailView $detail, string $tool): string
    {
        $card = $detail->card;
        $who = trim($card->recipientName . ($card->recipientEmail === '' ? '' : " ({$card->recipientEmail})"));

        return $tool === 'adjust_gift_card'
            ? "the gift card ending {$card->codeEnd}" . ($who === '' ? '' : " of {$who}")
            : 'the store credit of ' . ($who === '' ? 'the customer' : $who);
    }
}
