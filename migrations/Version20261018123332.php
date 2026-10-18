<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261018123332 extends Migration
{
    public function getDescription(): string
    {
        return 'Automatic discounts: coupons that need no code';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}coupons ADD automatic TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}coupons DROP automatic');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
