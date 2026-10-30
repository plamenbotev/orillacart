<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261017133557 extends Migration
{
    public function getDescription(): string
    {
        return 'Countries, states, tax groups and tax rates';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasTable('countries')) {
            $this->addSql('CREATE TABLE {prefix}countries (code CHAR(2) NOT NULL, alpha3 CHAR(3) NOT NULL, name VARCHAR(100) NOT NULL, in_eu TINYINT NOT NULL, UNIQUE INDEX alpha3 (alpha3), PRIMARY KEY (code)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('states')) {
            $this->addSql('CREATE TABLE {prefix}states (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(3) NOT NULL, name VARCHAR(100) NOT NULL, country_code CHAR(2) NOT NULL, UNIQUE INDEX country_state (country_code, code), INDEX IDX_37761615F026BB7C (country_code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('tax_groups')) {
            $this->addSql('CREATE TABLE {prefix}tax_groups (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('tax_rates')) {
            $this->addSql('CREATE TABLE {prefix}tax_rates (id INT AUTO_INCREMENT NOT NULL, country CHAR(2) NOT NULL, state VARCHAR(3) DEFAULT NULL, percent NUMERIC(7, 4) NOT NULL, name VARCHAR(64) NOT NULL, priority INT NOT NULL, group_id INT NOT NULL, INDEX group_location (group_id, country, state), INDEX IDX_25EB0C60FE54D947 (group_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasForeignKey('states', 'FK_37761615F026BB7C')) {
            $this->addSql('ALTER TABLE {prefix}states ADD CONSTRAINT FK_37761615F026BB7C FOREIGN KEY (country_code) REFERENCES {prefix}countries (code) ON DELETE CASCADE');
        }
        if (!$this->hasForeignKey('tax_rates', 'FK_25EB0C60FE54D947')) {
            $this->addSql('ALTER TABLE {prefix}tax_rates ADD CONSTRAINT FK_25EB0C60FE54D947 FOREIGN KEY (group_id) REFERENCES {prefix}tax_groups (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}states DROP FOREIGN KEY FK_37761615F026BB7C');
        $this->addSql('ALTER TABLE {prefix}tax_rates DROP FOREIGN KEY FK_25EB0C60FE54D947');
        $this->addSql('DROP TABLE {prefix}countries');
        $this->addSql('DROP TABLE {prefix}states');
        $this->addSql('DROP TABLE {prefix}tax_groups');
        $this->addSql('DROP TABLE {prefix}tax_rates');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
