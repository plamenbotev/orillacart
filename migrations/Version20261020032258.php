<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020032258 extends Migration
{
    public function getDescription(): string
    {
        return 'Products, variations and stockrooms may say how low their stock runs before the shop hears';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('products', 'low_stock_threshold')) {
            $this->addSql('ALTER TABLE {prefix}products ADD low_stock_threshold INT DEFAULT NULL');
        }
        if (!$this->hasColumn('stockrooms', 'low_stock_threshold')) {
            $this->addSql('ALTER TABLE {prefix}stockrooms ADD low_stock_threshold INT DEFAULT NULL');
        }
        if (!$this->hasColumn('variations', 'low_stock_threshold')) {
            $this->addSql('ALTER TABLE {prefix}variations ADD low_stock_threshold INT DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}products DROP low_stock_threshold');
        $this->addSql('ALTER TABLE {prefix}stockrooms DROP low_stock_threshold');
        $this->addSql('ALTER TABLE {prefix}variations DROP low_stock_threshold');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
