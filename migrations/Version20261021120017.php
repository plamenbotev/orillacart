<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Gift cards and store credit, with every change of their balances; what they paid of an order, and the codes a
 * cart holds.
 */
final class Version20261021120017 extends Migration
{
    public function getDescription(): string
    {
        return 'Gift cards and store credit';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasTable('gift_card_entries')) {
            $this->addSql('CREATE TABLE {prefix}gift_card_entries (id INT AUTO_INCREMENT NOT NULL, amount NUMERIC(19, 4) NOT NULL, note VARCHAR(255) NOT NULL, card_id INT NOT NULL, at DATETIME NOT NULL, order_id INT DEFAULT NULL, user_id INT DEFAULT NULL, INDEX gift_card_entries_of (card_id, id), INDEX gift_card_entries_of_order (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('gift_cards')) {
            $this->addSql('CREATE TABLE {prefix}gift_cards (id INT AUTO_INCREMENT NOT NULL, code_hash VARCHAR(64) DEFAULT NULL, code_end VARCHAR(4) NOT NULL, initial NUMERIC(19, 4) NOT NULL, balance NUMERIC(19, 4) NOT NULL, expires_at DATETIME DEFAULT NULL, active TINYINT NOT NULL, recipient_name VARCHAR(100) NOT NULL, recipient_email VARCHAR(254) NOT NULL, message VARCHAR(500) NOT NULL, kind VARCHAR(20) NOT NULL, currency CHAR(3) NOT NULL, customer_id INT DEFAULT NULL, order_id INT DEFAULT NULL, created_at DATETIME NOT NULL, INDEX gift_cards_of_customer (customer_id, kind), INDEX gift_cards_of_order (order_id), UNIQUE INDEX gift_card_code (code_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        // MySQL refuses a literal default on a JSON column: add them empty, fill them, then require them.
        if (!$this->hasColumn('carts', 'gift_cards')) {
            $this->addSql('ALTER TABLE {prefix}carts ADD gift_cards JSON DEFAULT NULL, ADD use_store_credit TINYINT DEFAULT 0 NOT NULL');
        }
        $this->addSql('UPDATE {prefix}carts SET gift_cards = \'[]\' WHERE gift_cards IS NULL');
        $this->addSql('ALTER TABLE {prefix}carts MODIFY gift_cards JSON NOT NULL');
        if (!$this->hasColumn('orders', 'gift_card_total')) {
            $this->addSql('ALTER TABLE {prefix}orders ADD gift_card_total NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, ADD gift_cards JSON DEFAULT NULL');
        }
        $this->addSql('UPDATE {prefix}orders SET gift_cards = \'[]\' WHERE gift_cards IS NULL');
        $this->addSql('ALTER TABLE {prefix}orders MODIFY gift_cards JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}gift_card_entries');
        $this->addSql('DROP TABLE {prefix}gift_cards');
        $this->addSql('ALTER TABLE {prefix}carts DROP gift_cards, DROP use_store_credit');
        $this->addSql('ALTER TABLE {prefix}orders DROP gift_card_total, DROP gift_cards');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
