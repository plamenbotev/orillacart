<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019110001 extends Migration
{
    public function getDescription(): string
    {
        return 'Orders keep no key: receipt links are signed and expire';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX order_key ON {prefix}orders');
        $this->addSql('ALTER TABLE {prefix}orders DROP order_key');
        $this->addSql('ALTER TABLE {prefix}payments DROP receipt_url');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}orders ADD order_key VARCHAR(32) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX order_key ON {prefix}orders (order_key)');
        $this->addSql('ALTER TABLE {prefix}payments ADD receipt_url VARCHAR(2000) NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
