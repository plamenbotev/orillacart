<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020075404 extends Migration
{
    public function getDescription(): string
    {
        return 'Carts left at checkout: the email and the ask to be reminded on the cart, and what was left, for the report';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasTable('abandoned_carts')) {
            $this->addSql('CREATE TABLE {prefix}abandoned_carts (id INT AUTO_INCREMENT NOT NULL, reminded_at DATETIME DEFAULT NULL, order_id INT DEFAULT NULL, order_total NUMERIC(19, 4) DEFAULT NULL, recovered_at DATETIME DEFAULT NULL, cart_id INT NOT NULL, customer_id INT DEFAULT NULL, email VARCHAR(254) DEFAULT NULL, items INT NOT NULL, subtotal NUMERIC(19, 4) NOT NULL, currency VARCHAR(3) NOT NULL, abandoned_at DATETIME NOT NULL, INDEX abandoned_at (abandoned_at), INDEX abandoned_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasColumn('carts', 'contact_email')) {
            $this->addSql('ALTER TABLE {prefix}carts ADD contact_email VARCHAR(254) DEFAULT NULL, ADD remind TINYINT DEFAULT 0 NOT NULL, ADD locale VARCHAR(20) DEFAULT NULL, ADD checkout_at DATETIME DEFAULT NULL, ADD abandoned_id INT DEFAULT NULL, ADD recover_hash VARCHAR(64) DEFAULT NULL');
        }
        if (!$this->hasIndex('carts', 'cart_checkout')) {
            $this->addSql('CREATE INDEX cart_checkout ON {prefix}carts (checkout_at)');
        }
        if (!$this->hasIndex('carts', 'cart_recover')) {
            $this->addSql('CREATE UNIQUE INDEX cart_recover ON {prefix}carts (recover_hash)');
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE {prefix}abandoned_carts');
        $this->addSql('DROP INDEX cart_checkout ON {prefix}carts');
        $this->addSql('DROP INDEX cart_recover ON {prefix}carts');
        $this->addSql('ALTER TABLE {prefix}carts DROP contact_email, DROP remind, DROP locale, DROP checkout_at, DROP abandoned_id, DROP recover_hash');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
