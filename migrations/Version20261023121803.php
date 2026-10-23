<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Orders keep the import scheme their goods came under, and the shop's number there when it charged the tax, for
 * the invoice.
 */
final class Version20261023121803 extends Migration
{
    public function getDescription(): string
    {
        return 'Orders keep their import scheme and the number it was charged under';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}orders ADD import_scheme VARCHAR(10) DEFAULT NULL, ADD import_number VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}orders DROP import_scheme, DROP import_number');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
