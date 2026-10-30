<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261029074740 extends Migration
{
    public function getDescription(): string
    {
        return 'The words of an order, and of its lines, in the shop\'s own language where the customer read another';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('order_lines', 'shop_words')) {
            $this->addSql('ALTER TABLE {prefix}order_lines ADD shop_words JSON DEFAULT NULL');
        }
        if (!$this->hasColumn('orders', 'shop_words')) {
            $this->addSql('ALTER TABLE {prefix}orders ADD shop_words JSON DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}orders DROP shop_words');
        $this->addSql('ALTER TABLE {prefix}order_lines DROP shop_words');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
