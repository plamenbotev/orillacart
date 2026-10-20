<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Application;

use OrillaCart\Newsletter\Application\Newsletter;

/**
 * Everyone subscribed to the newsletter now, with what they agreed to and when: for a newsletter service the
 * shop has no add-on for, or the shop's own records.
 */
final readonly class SubscribersExport
{
    public function __construct(private Newsletter $newsletter) {}

    public function export(ExportFile $file): void
    {
        $file->start(['email', 'subscribed_at', 'source', 'consent'], ['Email', 'Subscribed', 'Signed up at', 'Agreed to']);
        foreach ($this->newsletter->everyone() as $subscription) {
            $file->row([$subscription->email, $subscription->subscribedAt, $subscription->source, $subscription->consent]);
        }
        $file->finish();
    }
}
