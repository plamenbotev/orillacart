<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * A shopper's agent's checkout session follows its cart when the buyer logs in with it (PLAN 666): whether a
 * customer claimed it, and what the agent was last shown, sealed; it is found by its cart, no longer by a token.
 */
final class Version20261113210000 extends Migration
{
    public function getDescription(): string
    {
        return 'Checkout sessions of shoppers\' agents follow claimed carts';
    }

    public function up(Schema $schema): void
    {
        if ($this->hasColumn('agent_checkouts', 'claimed')) {
            return;
        }
        // Its checkout finds the session by the cart, claimed or not, not by the token the cart had.
        $this->addSql('ALTER TABLE {prefix}agent_checkouts DROP INDEX agent_checkout_token, DROP token_hash, ADD claimed TINYINT(1) DEFAULT 0 NOT NULL, ADD shown JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}agent_checkouts DROP claimed, DROP shown, ADD token_hash VARCHAR(64) NOT NULL DEFAULT \'\', ADD INDEX agent_checkout_token (token_hash)');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
