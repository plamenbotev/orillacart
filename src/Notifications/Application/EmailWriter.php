<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Notifications\Application\Dto\Email;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use OrillaCart\Shared\Lifecycle\Languages;
use OrillaCart\Shared\View\TemplateRenderer;

/**
 * Writes an email from its template, in the reader's language and from the shop's sender: the template's
 * "subject" block as plain text, its body as HTML and as plain text. Every template gets site_name, site_url
 * and lang (the reader's language, such as de-DE), and unsubscribe_url for a reader on the newsletter.
 */
final readonly class EmailWriter
{
    public function __construct(
        private TemplateRenderer $templates,
        private EmailSettingsRepository $settings,
        private Site $site,
        private Languages $languages,
        private ?UnsubscribeLinks $unsubscribe = null,
    ) {}

    /**
     * @param string|null          $locale  the reader's language; null for the site's
     * @param array<string, mixed> $context
     *
     * @throws EmailNotWritten its template failed: trying again fails the same way
     */
    public function write(string $template, string $kind, string $to, ?string $locale, array $context, ?string $replyTo = null, ?int $orderNumber = null): Email
    {
        $settings = $this->settings->load();
        $context += ['site_name' => $this->site->name(), 'site_url' => $this->site->url(), 'lang' => str_replace('_', '-', $locale ?? $this->languages->site())];
        // A reader subscribed to the newsletter can leave it from any of the shop's emails.
        $context += ['unsubscribe_url' => $to === $this->shopAddress() ? null : $this->unsubscribe?->for($to)];
        try {
            [$subject, $body] = $this->languages->in($locale, fn(): array => [
                // A subject is plain text: what the template escaped for HTML reads as written.
                html_entity_decode(trim($this->templates->renderBlock($template, 'subject', $context)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
                $this->templates->render($template, $context),
            ]);
        } catch (\Throwable $e) {
            throw new EmailNotWritten(\sprintf('The %s email could not be written: %s', $kind, $e->getMessage()), previous: $e);
        }

        return new Email(
            $to,
            $subject,
            $body,
            PlainText::fromHtml($body),
            $settings->fromName ?? $this->site->name(),
            $settings->fromAddress ?? $this->site->adminEmail(),
            $replyTo,
            $kind,
            $orderNumber,
        );
    }

    /** Where the shop's own emails go. */
    public function shopAddress(): string
    {
        return $this->settings->load()->shopAddress ?? $this->site->adminEmail();
    }
}
