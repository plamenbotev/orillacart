<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261018114340 extends Migration
{
    public function getDescription(): string
    {
        return 'Refunds: money given back per order, and what each order and line had refunded';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}refunds (id INT AUTO_INCREMENT NOT NULL, amount NUMERIC(19, 4) NOT NULL, status VARCHAR(20) NOT NULL, provider_reference VARCHAR(100) DEFAULT NULL, error LONGTEXT DEFAULT NULL, provider_pending TINYINT NOT NULL, settled_at DATETIME DEFAULT NULL, order_id INT NOT NULL, payment_id INT DEFAULT NULL, gateway VARCHAR(40) DEFAULT NULL, method VARCHAR(20) NOT NULL, currency VARCHAR(3) NOT NULL, quantities JSON NOT NULL, shipping TINYINT NOT NULL, restock TINYINT NOT NULL, reason LONGTEXT NOT NULL, user_id INT DEFAULT NULL, created_at DATETIME NOT NULL, INDEX refund_order (order_id), INDEX refund_reference (gateway, provider_reference), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE {prefix}order_lines ADD refunded_quantity INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE {prefix}orders ADD refunded_total NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, ADD shipping_refunded TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}refunds');
        $this->addSql('ALTER TABLE {prefix}orders DROP refunded_total, DROP shipping_refunded');
        $this->addSql('ALTER TABLE {prefix}order_lines DROP refunded_quantity');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
