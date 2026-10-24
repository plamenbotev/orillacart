<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Newsletter\Application\Dto\UpdateNewsletterSettings;
use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Newsletter\Domain\SignUpSource;
use OrillaCart\Newsletter\Infrastructure\WordPress\NewsletterUnsubscribeLinks;
use OrillaCart\Platform\WordPress\Security\SiteSignatures;

/** The newsletter's unsubscribe link: only for one subscribed, signed for their email alone. */
final class NewsletterLinksTest extends RestTestCase
{
    public function testTheLinkIsSignedForTheEmailItUnsubscribes(): void
    {
        $container = self::kernel()->container();
        $newsletter = $container->get(Newsletter::class);
        self::assertInstanceOf(Newsletter::class, $newsletter);
        $links = new NewsletterUnsubscribeLinks($newsletter, SiteSignatures::create());
        $newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', []));
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
}
