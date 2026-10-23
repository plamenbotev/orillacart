<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261023074253 extends Migration
{
    public function getDescription(): string
    {
        return 'Customers\' exemptions from tax';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}tax_exemptions (id INT AUTO_INCREMENT NOT NULL, reason VARCHAR(20) NOT NULL, certificate VARCHAR(64) NOT NULL, regions JSON NOT NULL, expires_on DATE DEFAULT NULL, note VARCHAR(200) NOT NULL, customer_id INT NOT NULL, created_at DATETIME NOT NULL, INDEX exemption_customer (customer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}tax_exemptions');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
