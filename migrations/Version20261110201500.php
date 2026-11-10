<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;
use OrillaCart\Shared\Domain\EmailAddress;

/**
 * Limits per customer go by who an email reaches, not how it is written (PLAN 434): ada+2@example.com and
 * a.d.a@gmail.com are ada@example.com and ada@gmail.com (EmailAddress::key()). Orders keep that key beside the
 * email, and coupon uses keep it in place of the lowercased email; both are worked out for those there are.
 *
 * Runs again harmlessly: the column and index are guarded, and each statement only sets a key to what it is.
 */
final class Version20261110201500 extends Migration
{
    public function getDescription(): string
    {
        return 'Email keys for limits per customer';
    }

    public function up(Schema $schema): void
    {
        if (!$this->hasColumn('orders', 'email_key')) {
            $this->addSql("ALTER TABLE {prefix}orders ADD email_key VARCHAR(200) DEFAULT '' NOT NULL");
        }
        if (!$this->hasIndex('orders', 'order_email_key')) {
            $this->addSql('CREATE INDEX order_email_key ON {prefix}orders (email_key)');
        }
        foreach ($this->connection->fetchFirstColumn('SELECT DISTINCT email FROM ' . $this->table('orders')) as $email) {
            if (!\is_string($email)) {
                continue;
            }
            $this->addSql('UPDATE {prefix}orders SET email_key = ? WHERE email = ?', [$email === 'anonymised@invalid' ? '' : EmailAddress::key($email), $email]);
        }
        foreach ($this->connection->fetchFirstColumn('SELECT DISTINCT email FROM ' . $this->table('coupon_redemptions')) as $email) {
            if (\is_string($email) && EmailAddress::key($email) !== $email) {
                $this->addSql('UPDATE {prefix}coupon_redemptions SET email = ? WHERE email = ?', [EmailAddress::key($email), $email]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX order_email_key ON {prefix}orders');
        $this->addSql('ALTER TABLE {prefix}orders DROP email_key');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
