<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application;

use OrillaCart\GiftCards\Application\Dto\AdjustBalance;
use OrillaCart\GiftCards\Application\Dto\ChangeGiftCard;
use OrillaCart\GiftCards\Application\Dto\GiftCardCredit;
use OrillaCart\GiftCards\Application\Dto\GiftCardDetailView;
use OrillaCart\GiftCards\Application\Dto\GiftCardEntryView;
use OrillaCart\GiftCards\Application\Dto\GiftCardPage;
use OrillaCart\GiftCards\Application\Dto\GiftCardQuery;
use OrillaCart\GiftCards\Application\Dto\GiftCardSettingsView;
use OrillaCart\GiftCards\Application\Dto\GiftCardView;
use OrillaCart\GiftCards\Application\Dto\IssuedGiftCardView;
use OrillaCart\GiftCards\Application\Dto\IssueGiftCard;
use OrillaCart\GiftCards\Domain\GiftCard;
use OrillaCart\GiftCards\Domain\GiftCardCode;
use OrillaCart\GiftCards\Domain\GiftCardEntry;
use OrillaCart\GiftCards\Domain\GiftCardNotFound;
use OrillaCart\GiftCards\Domain\GiftCardRepository;
use OrillaCart\GiftCards\Domain\GiftCardRuleViolation;
use OrillaCart\GiftCards\Domain\GiftCardSettings;
use OrillaCart\GiftCards\Domain\GiftCardSettingsRepository;
use OrillaCart\Reports\Application\ShopCalendar;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Shared\Users\UserNames;
use Psr\Clock\ClockInterface;

/**
 * Gift cards and store credit as the shop manages them: making a card and sending its code, a new code for a
 * lost one, turning one off, and adding to or taking off a balance, each change with who made it and why.
 */
final readonly class GiftCardService
{
    public function __construct(
        private GiftCardRepository $cards,
        private GiftCardSettingsRepository $settings,
        private GiftCardMail $mail,
        private SettingsService $shop,
        private Transactions $transactions,
        private UserNames $users,
        private ClockInterface $clock,
        private ShopCalendar $calendar,
    ) {}

    public function page(GiftCardQuery $query): GiftCardPage
    {
        $found = $this->cards->page(trim($query->search), $query->page, $query->perPage);

        return new GiftCardPage(array_map(GiftCardView::of(...), $found['cards']), $found['total'], $query->page, max(1, (int) ceil($found['total'] / $query->perPage)));
    }

    /**
     * @throws GiftCardNotFound
     */
    public function card(int $id): GiftCardDetailView
    {
        return $this->detail($this->get($id));
    }

    /**
     * Makes a gift card in the shop's currency, lasting as the settings say unless the command says until when,
     * and sends its code to whom it is for.
     *
     * @throws GiftCardRuleViolation
     */
    public function issue(IssueGiftCard $command, ?int $userId): IssuedGiftCardView
    {
        $now = $this->clock->now();
        $code = GiftCardCode::generate();
        $expires = $command->expiresAt === null || $command->expiresAt === '' ? $this->settings->load()->expiry($now) : $this->day($command->expiresAt);
        $card = GiftCard::issue($code, Money::of($command->amount), $this->shop->current()->currency, $now, $expires, $command->recipientName, $command->recipientEmail, $command->message);
        $this->transactions->run(function () use ($card, $now, $userId): void {
            $this->cards->save($card);
            $this->cards->add(new GiftCardEntry($card->id(), $card->initial(), 'Made by the shop', $now, null, $userId));
        });

        return new IssuedGiftCardView((string) $code, GiftCardView::of($card), $this->send($card, $code));
    }

    /**
     * A new code for a card whose code was lost or went to the wrong person; the old one pays no more.
     *
     * @throws GiftCardNotFound
     * @throws GiftCardRuleViolation for store credit, which has no code
     */
    public function newCode(int $id, ?int $userId): IssuedGiftCardView
    {
        $code = GiftCardCode::generate();
        $card = $this->transactions->run(function () use ($id, $code, $userId): GiftCard {
            $card = $this->get($id, true);
            $card->replaceCode($code);
            $this->cards->save($card);
            $this->cards->add(new GiftCardEntry($card->id(), Money::zero(), 'A new code replaced the old one', $this->clock->now(), null, $userId));

            return $card;
        });

        return new IssuedGiftCardView((string) $code, GiftCardView::of($card), $this->send($card, $code));
    }

    /**
     * @throws GiftCardNotFound
     * @throws GiftCardRuleViolation
     */
    public function change(int $id, ChangeGiftCard $command): GiftCardView
    {
        $card = $this->get($id);
        $card->change($command->active, $command->expiresAt === null || $command->expiresAt === '' ? null : $this->day($command->expiresAt));
        $this->cards->save($card);

        return GiftCardView::of($card);
    }

    /**
     * @throws GiftCardNotFound
     * @throws GiftCardRuleViolation when it would take off more than is left
     */
    public function adjust(int $id, AdjustBalance $command, ?int $userId): GiftCardDetailView
    {
        return $this->detail($this->transactions->run(function () use ($id, $command, $userId): GiftCard {
            $card = $this->get($id, true);
            $this->adjusted($card, $command, $userId);

            return $card;
        }));
    }

    /**
     * A customer's store credit, nothing until the shop adds some.
     */
    public function storeCredit(int $customerId): GiftCardDetailView
    {
        $card = $this->cards->storeCreditOf($customerId);

        return $card === null ? new GiftCardDetailView(GiftCardView::of(GiftCard::storeCredit($customerId, $this->shop->current()->currency, $this->clock->now())), []) : $this->detail($card);
    }

    /**
     * @throws GiftCardRuleViolation when it would take off more than is left
     */
    public function adjustStoreCredit(int $customerId, AdjustBalance $command, ?int $userId): GiftCardDetailView
    {
        return $this->detail($this->transactions->run(function () use ($customerId, $command, $userId): GiftCard {
            $card = $this->cards->storeCreditOf($customerId, true) ?? GiftCard::storeCredit($customerId, $this->shop->current()->currency, $this->clock->now());
            if (Money::of($command->amount)->isNegative() && $card->balance()->isZero()) {
                throw new GiftCardRuleViolation('The customer has no store credit to take off.', 'orillacart_gift_card_balance');
            }
            $this->cards->save($card);
            $this->adjusted($card, $command, $userId);

            return $card;
        }));
    }

    /**
     * Money given back for an order as something to spend in the shop: the customer's store credit, or for a
     * guest a new gift card, to send them once the refund is saved. Run it in the transaction that records the
     * refund. Neither expires: it is the customer's money, not a gift.
     */
    public function refundAsCredit(int $orderId, ?int $customerId, string $email, string $name, Money $amount, string $currency, ?int $userId): GiftCardCredit
    {
        $now = $this->clock->now();
        $note = \sprintf('Refund of order #%d', $orderId);
        if ($customerId !== null) {
            $card = $this->cards->storeCreditOf($customerId, true) ?? GiftCard::storeCredit($customerId, $currency, $now);
            $card->credit($amount);
            $this->cards->save($card);
            $this->cards->add(new GiftCardEntry($card->id(), $amount, $note, $now, $orderId, $userId));

            return new GiftCardCredit('as store credit');
        }
        $code = GiftCardCode::generate();
        $card = GiftCard::issue($code, $amount, $currency, $now, null, $name, $email);
        $this->cards->save($card);
        $this->cards->add(new GiftCardEntry($card->id(), $amount, $note, $now, $orderId, $userId));

        return new GiftCardCredit(\sprintf('as gift card ····%s, sent to %s', $card->codeEnd(), $email), fn(): bool => $this->send($card, $code));
    }

    /**
     * A privacy request erased the email: the cards made out to it forget who they were for.
     *
     * @return int how many cards
     */
    public function forgetRecipient(string $email): int
    {
        $cards = trim($email) === '' ? [] : $this->cards->toRecipient($email);
        foreach ($cards as $card) {
            $card->forgetRecipient();
            $this->cards->save($card);
        }

        return \count($cards);
    }

    public function settings(): GiftCardSettingsView
    {
        return GiftCardSettingsView::of($this->settings->load());
    }

    /**
     * @throws GiftCardRuleViolation
     */
    public function saveSettings(GiftCardSettingsView $command): GiftCardSettingsView
    {
        $settings = new GiftCardSettings(array_values(array_unique($command->productIds)), $command->validMonths);
        $this->settings->save($settings);

        return GiftCardSettingsView::of($settings);
    }

    private function adjusted(GiftCard $card, AdjustBalance $command, ?int $userId): void
    {
        $amount = Money::of($command->amount);
        if ($amount->isZero()) {
            throw new GiftCardRuleViolation('Add or take off more than nothing.', 'orillacart_invalid_gift_card_amount');
        }
        $card->credit($amount);
        $this->cards->save($card);
        $this->cards->add(new GiftCardEntry($card->id(), $amount, $command->note, $this->clock->now(), null, $userId));
    }

    /** A time the shop manager gave without a zone, such as the end of a day, is the shop's time. */
    private function day(string $at): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at, $this->calendar->timezone());
    }

    private function send(GiftCard $card, GiftCardCode $code): bool
    {
        return $card->recipientEmail() !== '' && $this->mail->send($card->recipientEmail(), (string) $code, (string) $card->balance(), $card->currency(), $card->expiresAt()?->format('Y-m-d'), $card->recipientName(), $card->message());
    }

    private function detail(GiftCard $card): GiftCardDetailView
    {
        $entries = array_reverse($this->cards->entries($card->id()));
        $names = $this->users->names(array_values(array_unique(array_filter(array_map(static fn(GiftCardEntry $entry): ?int => $entry->userId(), $entries), static fn(?int $id): bool => $id !== null))));

        return new GiftCardDetailView(GiftCardView::of($card), array_map(static fn(GiftCardEntry $entry): GiftCardEntryView => GiftCardEntryView::of($entry, $entry->userId() === null ? '' : $names[$entry->userId()] ?? ''), $entries));
    }

    /**
     * @throws GiftCardNotFound
     */
    private function get(int $id, bool $lock = false): GiftCard
    {
        return $this->cards->find($id, $lock) ?? throw new GiftCardNotFound($id);
    }
}
