<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A return keeps the refund of its items while the provider sends the money.
 */
final class Version20261024072028 extends Migration
{
    public function getDescription(): string
    {
        return 'Return refunds';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}returns ADD refund_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE {prefix}returns ADD CONSTRAINT FK_9E375A4D7B84676A FOREIGN KEY (refund_id) REFERENCES {prefix}refunds (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_EEA02102189801D5 ON {prefix}returns (refund_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}returns DROP FOREIGN KEY FK_9E375A4D7B84676A');
        $this->addSql('DROP INDEX IDX_EEA02102189801D5 ON {prefix}returns');
        $this->addSql('ALTER TABLE {prefix}returns DROP refund_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
