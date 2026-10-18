<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Orders keep the answers to add-ons' checkout fields.
 */
final class Version20261018163123 extends Migration
{
    public function getDescription(): string
    {
        return 'Orders keep the answers to add-ons\' checkout fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}orders ADD fields JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}orders DROP fields');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
