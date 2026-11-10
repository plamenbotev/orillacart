<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A customer has one store credit: the key on its customer and kind makes a second row for them impossible, so
 * two first credits at once add to the same balance instead of each making one, and one going unseen.
 */
final class Version20261110210000 extends Migration
{
    public function getDescription(): string
    {
        return 'One store credit per customer';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again. The new key is made
        // before the old index goes: it serves the lookups by customer the old one did.
        if (!$this->hasIndex('gift_cards', 'gift_card_of_customer', true)) {
            $this->mergeSecondCredits();
            $this->addSql('CREATE UNIQUE INDEX gift_card_of_customer ON {prefix}gift_cards (customer_id, kind)');
        }
        if ($this->hasIndex('gift_cards', 'gift_cards_of_customer')) {
            $this->addSql('DROP INDEX gift_cards_of_customer ON {prefix}gift_cards');
        }
    }

    /**
     * A customer two first credits each made a row for keeps the first: the others' entries become its own, its
     * balance is what its entries add up to (store credit starts empty and changes only by entries), and the
     * others go. In that order, each step leaving things as it found them when done twice, so the key is never
     * refused for the rows the race it ends left behind.
     */
    private function mergeSecondCredits(): void
    {
        $cards = $this->table('gift_cards');
        foreach ($this->connection->fetchAllAssociative("SELECT customer_id, kind, MIN(id) AS kept FROM {$cards} WHERE customer_id IS NOT NULL GROUP BY customer_id, kind HAVING COUNT(*) > 1") as $row) {
            if (!is_numeric($row['customer_id']) || !is_numeric($row['kept']) || !\is_string($row['kind'])) {
                continue;
            }
            [$customer, $kept] = [(int) $row['customer_id'], (int) $row['kept']];
            $others = array_map(intval(...), array_filter($this->connection->fetchFirstColumn("SELECT id FROM {$cards} WHERE customer_id = ? AND kind = ? AND id <> ?", [$customer, $row['kind'], $kept]), is_numeric(...)));
            if ($others === []) {
                continue;
            }
            $ids = implode(', ', $others);
            $this->addSql("UPDATE {prefix}gift_card_entries SET card_id = ? WHERE card_id IN ({$ids})", [$kept]);
            $this->addSql('UPDATE {prefix}gift_cards SET balance = (SELECT COALESCE(SUM(amount), 0) FROM {prefix}gift_card_entries WHERE card_id = ?) WHERE id = ?', [$kept, $kept]);
            $this->addSql("DELETE FROM {prefix}gift_cards WHERE id IN ({$ids})");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX gift_cards_of_customer ON {prefix}gift_cards (customer_id, kind)');
        $this->addSql('DROP INDEX gift_card_of_customer ON {prefix}gift_cards');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
