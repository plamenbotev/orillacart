<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Catalog\Application\Dto\OfferedVariation;
use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Catalog\Domain\ProductNotFound;
use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Notifications\Application\Dto\AskBackInStock;
use OrillaCart\Notifications\Domain\BackInStockRequest;
use OrillaCart\Notifications\Domain\BackInStockRequests;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailRuleViolation;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use Psr\Clock\ClockInterface;

/**
 * "Email me when it is back": a visitor leaves their email on a sold-out product or variation; when its stock
 * comes back, a job writes to each, once, in the language they asked in, and forgets them. Asking twice asks
 * once; what is in stock is not asked for. Requests are forgotten after half a year, and with the person's data.
 */
final readonly class BackInStock
{
    public const string KEEP = 'P6M';
    /** Emails a job sends before it comes back for the rest. */
    private const int BATCH = 50;
    /** Refusals of one recipient's email after which the request is let go. */
    public const int MAX_REFUSALS = 3;

    public function __construct(
        private BackInStockRequests $requests,
        private ShopCatalog $catalog,
        private EmailSettingsRepository $settings,
        private EmailWriter $writer,
        private Mailer $mailer,
        private Site $site,
        private Jobs $jobs,
        private ClockInterface $clock,
    ) {}

    /**
     * @throws ProductNotFound
     * @throws EmailRuleViolation for an item that is in stock, or not sold
     */
    public function ask(AskBackInStock $command, ?string $locale): void
    {
        if (!$this->settings->load()->sends(EmailKind::BackInStock)) {
            throw new EmailRuleViolation('The shop does not send this.', 'orillacart_back_in_stock_off');
        }
        $offer = $this->catalog->offer($command->productId);
        $soldOut = $offer->soldOut;
        // The original's stock, asked from a translation's page too (design 51).
        $item = "product:{$offer->id}";
        if ($command->variationId !== null) {
            $variation = array_values(array_filter($offer->variations, static fn(OfferedVariation $variation): bool => $variation->id === $command->variationId))[0] ?? null;
            $soldOut = $variation->soldOut ?? false;
            $item = "variation:{$command->variationId}";
        }
        if (!$soldOut) {
            throw new EmailRuleViolation('It is not sold out: you can order it now.', 'orillacart_not_sold_out');
        }
        $email = strtolower(trim($command->email));
        if (!$this->requests->has($item, $email)) {
            $this->requests->save(new BackInStockRequest($item, $offer->id, $email, $locale, $this->clock->now()));
        }
    }

    /** Stock of an item moved: when it came back and someone waits for it, they are written to. */
    public function stockChanged(string $item, ?int $before, ?int $after): void
    {
        $back = ($before !== null && $before <= 0) && ($after === null || $after > 0);
        if ($back && $this->requests->wanted($item)) {
            $this->jobs->push(SendBackInStock::TYPE, ['item' => $item], $item);
        }
    }

    /**
     * Writes to the next batch of those waiting for the item, forgetting each once its email went. Nothing goes
     * while the shop has these emails off, or once the item sold out again: those waiting go on waiting for its
     * next return. One the mail system refused is passed by, for the others, and let go once refused
     * MAX_REFUSALS times.
     *
     * @return bool whether some are left
     *
     * @throws EmailNotSent the mail system refused some; the others went, and the refused wait for the job's next try
     * @throws EmailNotWritten
     */
    public function send(string $item): bool
    {
        if (!$this->settings->load()->sends(EmailKind::BackInStock)) {
            return false;
        }
        $requests = $this->requests->forItem($item, self::BATCH);
        $refused = 0;
        $checked = false;
        foreach ($requests as $request) {
            try {
                $offer = $this->catalog->offer($request->productId());
            } catch (ProductNotFound) {
                $this->requests->remove($request);
                continue;
            }
            $variation = null;
            if (str_starts_with($item, 'variation:')) {
                $id = (int) substr($item, \strlen('variation:'));
                $variation = array_values(array_filter($offer->variations, static fn(OfferedVariation $offered): bool => $offered->id === $id))[0] ?? null;
            }
            // As it stands now, not as it stood when the job was queued: sold out again, nobody is told it is back.
            if (!$checked && ($variation->soldOut ?? $offer->soldOut)) {
                return false;
            }
            $checked = true;
            // The product's page in the language of the one who asked.
            $email = $this->writer->write(EmailKind::BackInStock->template(), EmailKind::BackInStock->value, $request->email(), $request->locale(), fn(): array => [
                'product' => ['name' => $offer->name, 'variation' => $variation?->label, 'url' => $this->site->productUrl($request->productId()) ?? $this->site->url()],
            ]);
            if ($this->mailer->send($email)) {
                $this->requests->remove($request);
                continue;
            }
            ++$refused;
            if ($request->refused() >= self::MAX_REFUSALS) {
                $this->requests->remove($request);
            } else {
                $this->requests->save($request);
            }
        }
        if ($refused > 0) {
            throw new EmailNotSent(\sprintf('%d back in stock email(s) were not sent: the mail system refused them. The others went.', $refused));
        }

        return \count($requests) === self::BATCH;
    }

    /** Forgets the requests of an email, as a privacy request asks. */
    public function forgetEmail(string $email): int
    {
        return $this->requests->forgetEmail(strtolower(trim($email)));
    }

    /** Forgets requests half a year old, daily. */
    public function forgetOld(): int
    {
        return $this->requests->forgetBefore($this->clock->now()->sub(new \DateInterval(self::KEEP)));
    }
}
