<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Shoppers' agents' checkout sessions (UCP, design 75): each one of the shop's carts, with the buyer and the
 * address the agent gave, sealed.
 */
final class Version20261113200000 extends Migration
{
    public function getDescription(): string
    {
        return 'Checkout sessions of shoppers\' agents';
    }

    public function up(Schema $schema): void
    {
        if ($this->hasTable('agent_checkouts')) {
            return;
        }
        $this->addSql('CREATE TABLE {prefix}agent_checkouts (id VARCHAR(40) NOT NULL, cart_id INT NOT NULL, cart_token VARCHAR(255) NOT NULL, token_hash VARCHAR(64) NOT NULL, agent VARCHAR(500) NOT NULL, status VARCHAR(30) NOT NULL, buyer JSON NOT NULL, address JSON DEFAULT NULL, shipping_option VARCHAR(120) DEFAULT NULL, order_id INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, INDEX agent_checkout_cart (cart_id), INDEX agent_checkout_token (token_hash), INDEX agent_checkout_expires (expires_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}agent_checkouts');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
