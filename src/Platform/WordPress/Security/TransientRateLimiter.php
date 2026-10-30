<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Security;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Platform\Domain\RateLimitWindow;
use OrillaCart\Shared\Http\Security\RateLimiter;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Counts in fixed windows, atomically: in the site's external object cache where it has one (add, then incr),
 * else in a row per window of the rate_limits table, in one INSERT … ON DUPLICATE KEY UPDATE; never a read and
 * a write apart, which two requests at once could both make and count as one. The limits are
 * {guard: [how many, in seconds]}, changed with the filter orillacart_rate_limits; a guard without one is not
 * counted. The caller's address is ClientAddress's: behind a proxy the site trusts, the visitor's.
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
        'receipts' => [60, 600],
        'receipt_links' => [5, 3600],
        'order_lookup' => [5, 3600],
        'back_in_stock' => [10, 3600],
        // Each may cost the shop a lookup at an address register.
        'address_check' => [30, 3600],
        'returns' => [10, 3600],
        'reviews' => [10, 3600],
        'form_uploads' => [20, 3600],
        'tax_certificates' => [10, 3600],
        // Finding an order by its number and email, and declaring the withdrawal from it.
        'withdrawals' => [20, 3600],
        // Each asks a payment provider or courier, with the shop's keys.
        'settings_tests' => [20, 600],
        // OAuth for AI assistants: anyone may register an app or ask for tokens.
        'oauth_register' => [20, 3600],
        'oauth_token' => [120, 600],
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
        $limits = apply_filters('orillacart_rate_limits', self::LIMITS);
        $limit = \is_array($limits) ? ($limits[$guard] ?? null) : null;
        if (!\is_array($limit) || !\is_int($limit[0] ?? null) || !\is_int($limit[1] ?? null) || $limit[1] < 1) {
            return null;
        }
        [$max, $seconds] = [$limit[0], $limit[1]];
        $now = time();
        $window = intdiv($now, $seconds);
        $ends = ($window + 1) * $seconds;
        $bucket = md5("{$guard}|{$caller}|{$window}");
        $count = $this->cached($bucket, $ends - $now) ?? $this->counted($bucket, $ends, $now);

        return $count > $max ? $ends - $now : null;
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
        $connection = $entityManager->getConnection();
        $table = $entityManager->getClassMetadata(RateLimitWindow::class)->getTableName();
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
