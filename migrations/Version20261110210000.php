<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A customer has one store credit: the key on its customer and kind makes a second row for them impossible, so
 * two first credits at once add to the same balance instead of each making one, and one going unseen.
 */
final class Version20261110210000 extends Migration
{
    public function getDescription(): string
    {
        return 'One store credit per customer';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again. The new key is made
        // before the old index goes: it serves the lookups by customer the old one did.
        if (!$this->hasIndex('gift_cards', 'gift_card_of_customer', true)) {
            $this->addSql('CREATE UNIQUE INDEX gift_card_of_customer ON {prefix}gift_cards (customer_id, kind)');
        }
        if ($this->hasIndex('gift_cards', 'gift_cards_of_customer')) {
            $this->addSql('DROP INDEX gift_cards_of_customer ON {prefix}gift_cards');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX gift_cards_of_customer ON {prefix}gift_cards (customer_id, kind)');
        $this->addSql('DROP INDEX gift_card_of_customer ON {prefix}gift_cards');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
