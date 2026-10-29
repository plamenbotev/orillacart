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
        private EmailPreviews $previews = new EmailPreviews(),
    ) {}

    /**
     * Writes previews: the customer's own links (unsubscribing) lead to the shop's home page instead.
     *
     * @template T
     *
     * @param \Closure(): T $work
     * @param string|null   $locale the language they are written in; null for each reader's
     *
     * @return T
     */
    public function previewing(\Closure $work, ?string $locale = null): mixed
    {
        return $this->previews->during($work, $locale);
    }

    /**
     * @param string|null                                          $locale  the reader's language; null for the site's
     * @param array<string, mixed>|\Closure(): array<string, mixed> $context a closure is called in the reader's language,
     *                                                                       so the pages it links to are in it
     *
     * @throws EmailNotWritten its template failed: trying again fails the same way
     */
    public function write(string $template, string $kind, string $to, ?string $locale, array|\Closure $context, ?string $replyTo = null, ?int $orderNumber = null): Email
    {
        $settings = $this->settings->load();
        $locale = $this->previews->locale() ?? $locale;
        try {
            [$subject, $body] = $this->languages->in($locale, function () use ($template, $to, $locale, $context): array {
                $context = ($context instanceof \Closure ? $context() : $context)
                    + ['site_name' => $this->site->name(), 'site_url' => $this->site->url(), 'lang' => str_replace('_', '-', $locale ?? $this->languages->site())];
                // A reader subscribed to the newsletter can leave it from any of the shop's emails.
                // A preview shows where the link goes without giving away the customer's.
                $context += ['unsubscribe_url' => match (true) {
                    $to === $this->shopAddress() => null,
                    $this->previews->active() => $this->unsubscribe === null ? null : $this->site->url(),
                    default => $this->unsubscribe?->for($to),
                }];

                return [
                    // A subject is plain text: what the template escaped for HTML reads as written.
                    html_entity_decode(trim($this->templates->renderBlock($template, 'subject', $context)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
                    $this->templates->render($template, $context),
                ];
            });
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
