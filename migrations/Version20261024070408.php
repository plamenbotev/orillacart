<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A payment keeps the provider's account or mode it was made in (PayPal's sandbox or live).
 */
final class Version20261024070408 extends Migration
{
    public function getDescription(): string
    {
        return 'Payment accounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}payments ADD account VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}payments DROP account');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
