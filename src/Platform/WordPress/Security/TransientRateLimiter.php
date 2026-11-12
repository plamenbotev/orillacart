<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Platform\Domain\RateLimitWindow;
use OrillaCart\Shared\Http\Security\CallerKeys;
use OrillaCart\Shared\Http\Security\RateLimiter;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Counts in fixed windows, atomically: in the site's external object cache where it has one (add, then incr),
 * else in a row per window of the rate_limits table, in one INSERT … ON DUPLICATE KEY UPDATE; never a read and
 * a write apart, which two requests at once could both make and count as one. A count made while the shop's
 * connection is inside a transaction is made on a connection of its own where the site's tables are on the
 * database server WordPress is configured with, so a rollback (a failed checkout, an extension's run undone)
 * does not undo it. The limits are {guard: [how many, in seconds]}, changed with the
 * filter orillacart_rate_limits: a guard of the shop's keeps its own limit when the filter drops it or gives
 * something that is no limit, so a filter can raise or lower a limit but not switch it off; a guard the shop does
 * not know is counted only when the filter gives it a limit. The caller's address is ClientAddress's: behind a
 * proxy the site trusts, the visitor's.
 */
final readonly class TransientRateLimiter implements RateLimiter
{
    private const string CACHE_GROUP = 'orillacart_rate_limits';

    /** @var array<string, array{int, int}> */
    private const array LIMITS = [
        'accounts' => [5, 3600],
        // Account emails to one address from one caller, and from everyone: the shop's mail is not a way to flood
        // someone, nor to keep them from making their account.
        'account_emails' => [3, 86400],
        'account_emails_any' => [20, 86400],
        'account_links' => [10, 3600],
        'coupons' => [20, 600],
        'gift_cards' => [10, 600],
        'checkout' => [30, 600],
        // A cart made or filled, and the email given at checkout (which may send a reminder).
        'cart' => [120, 600],
        'checkout_contact' => [20, 600],
        'session' => [120, 600],
        // The checkout summary priced again as the form changes, a bundle priced as options are picked, store
        // credit and gift cards taken off the cart: each prices the whole cart, and may ask a carrier or a register.
        'checkout_summary' => [240, 600],
        'quotes' => [240, 600],
        // VAT numbers checked at VIES that it had not been asked about: each is a call out, and a row kept a day.
        'vat_checks' => [20, 3600],
        'receipts' => [60, 600],
        'receipt_links' => [5, 3600],
        'order_lookup' => [5, 3600],
        'back_in_stock' => [10, 3600],
        // To one address, from everyone: carts it is reminded of, items it waits for.
        'reminder_address' => [3, 86400],
        'back_in_stock_address' => [10, 86400],
        // Each may cost the shop a lookup at an address register.
        'address_check' => [30, 3600],
        'returns' => [10, 3600],
        'reviews' => [10, 3600],
        'form_uploads' => [20, 3600],
        'tax_certificates' => [10, 3600],
        // Finding an order by its number and email, and declaring the withdrawal from it.
        'withdrawals' => [20, 3600],
        // The links that open an order's withdrawal, mailed to its address, from everyone together.
        'withdrawal_links' => [3, 86400],
        // The links that confirm a guest's email for ordering: from one caller, which is how many mailboxes one
        // place may order by a day, and to one address from everyone.
        'email_confirmations' => [5, 86400],
        'email_confirmation_address' => [3, 86400],
        // And the links followed and confirmed.
        'email_confirmed' => [20, 3600],
        // Each asks a payment provider or courier, with the shop's keys.
        'settings_tests' => [20, 600],
        // An extension's routes, all of them together, per caller.
        'extensions' => [120, 600],
        // What one extension's scripts send out, all their runs together.
        'extension_emails' => [50, 3600],
        'extension_http' => [500, 3600],
        // OAuth for AI assistants: anyone may register an app or ask for tokens.
        'oauth_register' => [20, 3600],
        // And from the network around the caller (an IPv6 /48, an IPv4 /24): the unconnected apps the shop keeps
        // at once are a soft limit, which one provider's customer, with all of its /64s, must not fill alone.
        'oauth_register_network' => [60, 3600],
        'oauth_token' => [120, 600],
        // Payment providers' webhooks, per caller: PayPal and Stripe send from few addresses, a flood from one is
        // not theirs.
        'payment_notifications' => [1200, 600],
        // PayPal's webhook certificates fetched, and webhooks PayPal is asked to verify, by the whole site: each
        // is a call out, whoever sent the webhook. And webhooks refused: the log tells of them only this often.
        'paypal_certificates' => [10, 3600],
        'paypal_verifications' => [60, 3600],
        'paypal_refused_notifications' => [20, 3600],
    ];

    /**
     * The limits before sites change them: how many tries, in how many seconds, by guard.
     *
     * @return array<string, array{int, int}>
     */
    public static function limits(): array
    {
        return self::LIMITS;
    }

    /**
     * @param \Closure(): EntityManagerInterface $entityManager late: a site with an object cache never opens it
     *                                                       for this, and the HTTP kernel builds without a database
     */
    public function __construct(
        #[AutowireServiceClosure(EntityManagerInterface::class)]
        private \Closure $entityManager,
    ) {}

    public function hit(string $guard, string $caller): ?int
    {
        $limit = self::limitOf(apply_filters('orillacart_rate_limits', self::LIMITS), $guard);
        if ($limit === null) {
            return null;
        }
        [$max, $seconds] = $limit;
        $caller = self::network($caller);
        $now = time();
        $window = intdiv($now, $seconds);
        $ends = ($window + 1) * $seconds;
        $bucket = md5("{$guard}|{$caller}|{$window}");
        $count = $this->cached($bucket, $ends - $now) ?? $this->counted($bucket, $ends, $now);

        return $count > $max ? $ends - $now : null;
    }

    /**
     * The limit of a guard from what the filter gave: its own when it is one, else the shop's for that guard.
     *
     * @return array{int, int}|null how many, in how many seconds; null for a guard with no limit
     */
    public static function limitOf(mixed $limits, string $guard): ?array
    {
        $limit = \is_array($limits) ? ($limits[$guard] ?? null) : null;
        if (\is_array($limit) && \is_int($limit[0] ?? null) && \is_int($limit[1] ?? null) && $limit[0] >= 0 && $limit[1] >= 1) {
            return [$limit[0], $limit[1]];
        }

        return self::LIMITS[$guard] ?? null;
    }

    /** A caller as the limits count it: CallerKeys::network(). */
    public static function network(string $caller): string
    {
        return CallerKeys::network($caller);
    }

    /** The count in the external object cache, whose add and incr are atomic; null for a site without one. */
    private function cached(string $bucket, int $seconds): ?int
    {
        if (!wp_using_ext_object_cache()) {
            return null;
        }
        wp_cache_add($bucket, 0, self::CACHE_GROUP, max(1, $seconds));
        $count = wp_cache_incr($bucket, 1, self::CACHE_GROUP);

        return \is_int($count) ? $count : null;
    }

    /**
     * The count in the window's row, made or raised in one statement. LAST_INSERT_ID(expr) remembers the count
     * for this connection, whichever branch ran. The first hit of a window sweeps the rows of windows that ended.
     */
    private function counted(string $bucket, int $ends, int $now): int
    {
        $entityManager = ($this->entityManager)();
        $shared = $entityManager->getConnection();
        $table = $entityManager->getClassMetadata(RateLimitWindow::class)->getTableName();
        if (!$shared->isTransactionActive()) {
            return self::count($shared, $table, $bucket, $ends, $now);
        }
        // The shop's connection is WordPress's, whose transaction would hold the count: a link of its own, made
        // from the site's database settings without the shop's driver, commits it at once. Where the site's tables
        // are not on that server (a network spread over several), the count goes with the transaction as before.
        try {
            // @phpstan-ignore argument.type (the parameters the shop's own connection was made with)
            $own = DriverManager::getConnection($shared->getParams());
        } catch (\Throwable) {
            return self::count($shared, $table, $bucket, $ends, $now);
        }
        try {
            return self::count($own, $table, $bucket, $ends, $now);
        } catch (\Throwable) {
            return self::count($shared, $table, $bucket, $ends, $now);
        } finally {
            $own->close();
        }
    }

    private static function count(Connection $connection, string $table, string $bucket, int $ends, int $now): int
    {
        $connection->executeStatement(
            "INSERT INTO {$table} (bucket, hits, expires_at) VALUES (?, LAST_INSERT_ID(1), ?) ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)",
            [$bucket, gmdate('Y-m-d H:i:s', $ends)],
        );
        $count = $connection->fetchOne('SELECT LAST_INSERT_ID()');
        $count = is_numeric($count) ? (int) $count : 1;
        if ($count === 1) {
            $connection->executeStatement("DELETE FROM {$table} WHERE expires_at < ?", [gmdate('Y-m-d H:i:s', $now)]);
        }

        return $count;
    }
}
