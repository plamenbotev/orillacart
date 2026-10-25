<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Step;

use OrillaCart\Notifications\Application\EmailSettingsService;
use OrillaCart\Notifications\Application\Site;
use OrillaCart\Setup\Application\Dto\EmailAnswers;
use OrillaCart\Setup\Application\Dto\PlannedChange;
use OrillaCart\Setup\Application\SetupAnswers;
use OrillaCart\Setup\Application\SetupStep;
use OrillaCart\Shared\Domain\Text;

/**
 * Emails: who they come from and where new orders go. An address of another domain than the site's is a
 * warning: mail sent from it without that domain's permission (SPF, DKIM) lands in spam. The test email the
 * setup sends says whether the site could send it at all.
 */
final readonly class EmailsStep implements SetupStep
{
    public const string KEY = 'emails';
    private const string SENDER = 'emails.sender';

    public function __construct(
        private EmailSettingsService $emails,
        private Site $site,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function position(): int
    {
        return 70;
    }

    public function answers(): string
    {
        return EmailAnswers::class;
    }

    public function plan(SetupAnswers $answers): array
    {
        $emails = $answers->of(self::KEY, EmailAnswers::class);
        if ($emails === null) {
            return [];
        }
        $name = trim($emails->fromName);
        $from = trim($emails->fromAddress);
        $shop = $emails->shopAddress === null || trim($emails->shopAddress) === '' ? $from : trim($emails->shopAddress);
        $current = $this->emails->current();
        if ([$current->fromName, $current->fromAddress, $current->shopAddress] === [$name, $from, $shop]) {
            return [];
        }
        $site = strtolower((string) parse_url($this->site->url(), PHP_URL_HOST));
        $domain = strtolower(substr((string) strrchr($from, '@'), 1));
        $own = $domain === $site || str_ends_with($site, '.' . $domain);

        return [new PlannedChange(
            self::SENDER,
            self::KEY,
            Text::of('Emails come from "%1$s" <%2$s>; new orders go to %3$s.', $name, $from, $shop),
            data: ['name' => $name, 'from' => $from, 'shop' => $shop],
            warning: $own ? null : Text::of('Mail from %1$s may land in spam: the site is %2$s. Use an address of the site\'s own domain, or a mail plugin that sends through %3$s.', $from, $site, $domain),
        )];
    }

    public function apply(PlannedChange $change, SetupAnswers $answers): void
    {
        $text = static fn(string $key): ?string => \is_string($change->data[$key] ?? null) ? $change->data[$key] : null;
        $this->emails->sender($text('name'), $text('from'), $text('shop'));
    }
}
