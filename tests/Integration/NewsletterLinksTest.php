<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Newsletter\Application\Dto\UpdateNewsletterSettings;
use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Newsletter\Domain\SignUpSource;
use OrillaCart\Newsletter\Infrastructure\WordPress\NewsletterConfirmLinks;
use OrillaCart\Newsletter\Infrastructure\WordPress\NewsletterUnsubscribeLinks;
use OrillaCart\Platform\WordPress\Security\SiteSignatures;
use Symfony\Component\Clock\MockClock;

/**
 * The newsletter's unsubscribe link: only for one subscribed, signed for their email alone. The confirm link is
 * signed for the email and the day, and works for a week.
 */
final class NewsletterLinksTest extends RestTestCase
{
    public function testTheLinkIsSignedForTheEmailItUnsubscribes(): void
    {
        $container = self::kernel()->container();
        $newsletter = $container->get(Newsletter::class);
        self::assertInstanceOf(Newsletter::class, $newsletter);
        $links = new NewsletterUnsubscribeLinks($newsletter, SiteSignatures::create());
        $newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', [], confirmNew: false));
        $email = 'guest-' . bin2hex(random_bytes(4)) . '@example.com';
        self::assertNull($links->for($email), 'not subscribed: no link');

        $newsletter->subscribe($email, 'Ada', 'Lovelace', SignUpSource::Checkout);
        $link = $links->for($email);
        self::assertIsString($link);
        parse_str((string) parse_url($link, \PHP_URL_QUERY), $query);
        self::assertIsString($query['sig'] ?? null);
        self::assertTrue($links->verify($email, $query['sig']));
        self::assertFalse($links->verify('grace@example.com', $query['sig']), 'not someone else\'s');

        $newsletter->erase($email);
    }

    public function testTheConfirmLinkIsSignedForItsDayAndExpires(): void
    {
        $clock = new MockClock('2026-11-10 12:00:00 UTC');
        $links = new NewsletterConfirmLinks(SiteSignatures::create(), $clock);
        parse_str((string) parse_url($links->for('Ada@Example.com'), \PHP_URL_QUERY), $query);
        self::assertIsString($query['sig'] ?? null);
        self::assertIsString($query['day'] ?? null);
        self::assertTrue($links->verify('ada@example.com', $query['day'], $query['sig']));
        self::assertFalse($links->verify('ada@example.com', (string) ((int) $query['day'] + 1), $query['sig']), 'another day: not signed for it');
        self::assertFalse($links->verify('grace@example.com', $query['day'], $query['sig']));
        self::assertFalse($links->expired($query['day']));

        $clock->modify('+6 days');
        self::assertFalse($links->expired($query['day']), 'six days on');
        $clock->modify('+1 day');
        self::assertTrue($links->expired($query['day']), 'a week on');
    }
}
