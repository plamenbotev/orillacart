<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Check;

use OrillaCart\Notifications\Application\EmailSettingsService;
use OrillaCart\Notifications\Application\Site;
use OrillaCart\Setup\Application\Dto\Readiness;
use OrillaCart\Setup\Application\ReadinessCheck;
use OrillaCart\Shared\Domain\Text;

/** Emails from an address of the site's own domain, which mail servers trust more than another's. */
final readonly class EmailReadiness implements ReadinessCheck
{
    public function __construct(
        private EmailSettingsService $emails,
        private Site $site,
    ) {}

    public function check(): array
    {
        $current = $this->emails->current();
        $from = $current->fromAddress ?? $current->defaultAddress;
        $site = strtolower((string) parse_url($this->site->url(), PHP_URL_HOST));
        $domain = strtolower(substr((string) strrchr($from, '@'), 1));

        return $domain === $site || str_ends_with($site, '.' . $domain)
            ? []
            : [Readiness::advice('emails.domain', Text::of('Emails come from %1$s, not an address of %2$s: they may land in spam.', $from, $site), 'emails')];
    }
}
