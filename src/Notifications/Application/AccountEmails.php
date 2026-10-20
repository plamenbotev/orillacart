<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Customers\Application\AccountMail;
use OrillaCart\Notifications\Application\Dto\Email;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use OrillaCart\Shared\Lifecycle\Languages;
use OrillaCart\Shared\View\TemplateRenderer;
use Psr\Log\LoggerInterface;

/**
 * The emails signing up sends, in the visitor's language, from templates/emails/account-*.html.twig. Sent at
 * once: the visitor waits for the link. One that fails is logged, and the visitor asks again. The welcome is the
 * shop's to turn off.
 */
final readonly class AccountEmails implements AccountMail
{
    public function __construct(
        private TemplateRenderer $templates,
        private Mailer $mailer,
        private EmailSettingsRepository $settings,
        private Site $site,
        private Languages $languages,
        private LoggerInterface $logger,
    ) {}

    public function confirm(string $email, string $firstName, string $link, \DateTimeImmutable $until): void
    {
        $this->send($email, 'emails/account-confirm.html.twig', 'account_confirm', ['first_name' => $firstName, 'link' => $link, 'until' => $until->format(\DATE_ATOM)]);
    }

    public function exists(string $email, string $loginUrl, string $lostPasswordUrl): void
    {
        $this->send($email, 'emails/account-exists.html.twig', 'account_exists', ['login_url' => $loginUrl, 'lost_password_url' => $lostPasswordUrl]);
    }

    public function welcome(string $email, string $firstName, string $accountUrl): void
    {
        if ($this->settings->load()->sends(EmailKind::AccountWelcome)) {
            $this->send($email, EmailKind::AccountWelcome->template(), EmailKind::AccountWelcome->value, ['first_name' => $firstName, 'account_url' => $accountUrl]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(string $to, string $template, string $kind, array $context): void
    {
        $settings = $this->settings->load();
        $context += ['site_name' => $this->site->name(), 'site_url' => $this->site->url(), 'lang' => str_replace('_', '-', $this->languages->current())];
        try {
            $body = $this->templates->render($template, $context);
            $email = new Email(
                $to,
                html_entity_decode(trim($this->templates->renderBlock($template, 'subject', $context)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
                $body,
                PlainText::fromHtml($body),
                $settings->fromName ?? $this->site->name(),
                $settings->fromAddress ?? $this->site->adminEmail(),
                null,
                $kind,
            );
        } catch (\Throwable $e) {
            $this->logger->error('The {kind} email could not be written: {message}', ['kind' => $kind, 'message' => $e->getMessage(), 'exception' => $e]);

            return;
        }
        if (!$this->mailer->send($email)) {
            $this->logger->warning('The {kind} email was not sent: the mail system refused it.', ['kind' => $kind]);
        }
    }
}
