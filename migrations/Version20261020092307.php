<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020092307 extends Migration
{
    public function getDescription(): string
    {
        return 'Fees on orders, such as for the way to pay, and the fee of each payment method';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}orders ADD fees JSON DEFAULT NULL, ADD fees_total NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, ADD fees_tax NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL');
        // Orders placed before had no fees.
        $this->addSql('UPDATE {prefix}orders SET fees = \'[]\'');
        $this->addSql('ALTER TABLE {prefix}orders MODIFY fees JSON NOT NULL');
        $this->addSql('ALTER TABLE {prefix}payment_methods ADD fee NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, ADD fee_percent NUMERIC(7, 4) DEFAULT \'0.0000\' NOT NULL, ADD fee_label VARCHAR(100) DEFAULT \'\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}orders DROP fees, DROP fees_total, DROP fees_tax');
        $this->addSql('ALTER TABLE {prefix}payment_methods DROP fee, DROP fee_percent, DROP fee_label');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
