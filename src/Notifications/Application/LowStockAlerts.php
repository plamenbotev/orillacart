<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Catalog\Application\Dto\LowStockView;
use OrillaCart\Catalog\Application\LowStock;
use OrillaCart\Notifications\Application\Dto\Email;
use OrillaCart\Notifications\Domain\AlertedStock;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use Psr\Log\LoggerInterface;

/**
 * Tells the shop, once a day, what its stockrooms hold few of: only when something ran low since the last
 * email, which then lists everything low, what is new marked. An item restocked and low again is new again.
 */
final readonly class LowStockAlerts
{
    public function __construct(
        private LowStock $stock,
        private AlertedStock $alerted,
        private EmailSettingsRepository $settings,
        private EmailWriter $writer,
        private Mailer $mailer,
        private LoggerInterface $logger,
    ) {}

    /** @return bool whether an email went */
    public function alert(): bool
    {
        if (!$this->settings->load()->sends(EmailKind::LowStock)) {
            return false;
        }
        $low = $this->stock->items();
        $keys = array_map(static fn(LowStockView $item): string => $item->key(), $low);
        $before = $this->alerted->keys();
        if (array_diff($keys, $before) === []) {
            // Nothing new; what was restocked is forgotten, so it is new when it runs low again.
            $this->alerted->remember(array_values(array_intersect($before, $keys)));

            return false;
        }
        try {
            $email = $this->write(self::items($low, $before));
        } catch (EmailNotWritten $e) {
            $this->logger->error('The low stock email could not be written: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return false;
        }
        if (!$this->mailer->send($email)) {
            // Tried again tomorrow, with whatever is low then.
            $this->logger->warning('The low stock email was not sent: the mail system refused it.');

            return false;
        }
        $this->alerted->remember($keys);

        return true;
    }

    /**
     * The email as it would go now, for the shop to look at while editing its template; with an item made up
     * when nothing is low.
     *
     * @throws EmailNotWritten
     */
    public function preview(): Email
    {
        $items = self::items($this->stock->items(), []);

        return $this->write($items !== [] ? $items : [['name' => 'Brass lamp', 'sku' => 'LAMP-1', 'stockroom' => 'Main', 'quantity' => 1, 'threshold' => 2, 'new' => true]]);
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @throws EmailNotWritten
     */
    private function write(array $items): Email
    {
        return $this->writer->write(EmailKind::LowStock->template(), EmailKind::LowStock->value, $this->writer->shopAddress(), null, ['items' => $items]);
    }

    /**
     * @param list<LowStockView> $low
     * @param list<string>       $before what the last email named
     *
     * @return list<array<string, mixed>>
     */
    private static function items(array $low, array $before): array
    {
        return array_map(static fn(LowStockView $item): array => [
            'name' => $item->name,
            'sku' => $item->sku,
            'stockroom' => $item->stockroom,
            'quantity' => $item->quantity,
            'threshold' => $item->threshold,
            'new' => !\in_array($item->key(), $before, true),
        ], $low);
    }
}
