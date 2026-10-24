<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Notifications\Application\Dto\Email;
use OrillaCart\Notifications\Application\Dto\EmailPreviewView;
use OrillaCart\Notifications\Application\Dto\EmailTemplateSourceView;
use OrillaCart\Notifications\Application\Dto\EmailTemplateView;
use OrillaCart\Notifications\Application\Dto\PreviewEmail;
use OrillaCart\Notifications\Application\Dto\SaveEmailTemplate;
use OrillaCart\Notifications\Application\Dto\TestEmailView;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailRuleViolation;
use OrillaCart\Notifications\Domain\EmailTemplate;
use OrillaCart\Notifications\Domain\EmailTemplateNotFound;
use OrillaCart\Notifications\Domain\EmailTemplateRepository;
use OrillaCart\Ordering\Application\Dto\OrderQuery;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Shared\View\TemplateEditing;
use OrillaCart\Shared\View\TemplateRejected;
use Psr\Clock\ClockInterface;

/**
 * The shop writes its emails' templates in the admin: each of the files under templates/emails/ (as a theme
 * overrides it) can be replaced by the shop's own Twig, which runs in the sandbox and is checked before it is
 * saved. A preview shows the email the template makes, saved or as edited, with a real order; a test sends it to
 * whoever asks. Resetting goes back to the file.
 */
final readonly class EmailTemplateEditor
{
    private const array ACCOUNT_EMAILS = ['account-confirm' => 'account_confirm', 'account-exists' => 'account_exists'];

    public function __construct(
        private TemplateEditing $editing,
        private EmailTemplateRepository $templates,
        private OrderEmails $orderEmails,
        private LowStockAlerts $lowStock,
        private CartReminders $cartReminders,
        private OrderService $orders,
        private EmailWriter $writer,
        private Mailer $mailer,
        private Site $site,
        private ClockInterface $clock,
    ) {}

    /** @return list<string> the emails a preview can show */
    public static function previewKinds(): array
    {
        return [...EmailKind::values(), ...array_values(self::ACCOUNT_EMAILS)];
    }

    /** @return list<EmailTemplateView> by name */
    public function templates(): array
    {
        $edited = [];
        foreach ($this->templates->all() as $template) {
            $edited[$template->name()] = $template;
        }

        return array_map(function (string $file) use ($edited): EmailTemplateView {
            $name = self::shortName($file);

            return new EmailTemplateView($name, isset($edited[$file]), self::previews($name), isset($edited[$file]) ? $edited[$file]->updatedAt()->format(\DATE_ATOM) : null);
        }, $this->editing->names());
    }

    /**
     * @throws EmailTemplateNotFound
     */
    public function template(string $name): EmailTemplateSourceView
    {
        $file = $this->file($name);
        $edited = $this->templates->find($file);
        $fileSource = $this->editing->fileSource($file);

        return new EmailTemplateSourceView($name, $edited?->source() ?? $fileSource ?? '', $fileSource, $edited !== null, self::previews($name), $edited?->updatedAt()->format(\DATE_ATOM));
    }

    /**
     * @throws EmailTemplateNotFound
     * @throws TemplateRejected     for a template that does not compile, or uses what the sandbox does not allow
     * @throws EmailRuleViolation   for an empty template, or one too long
     */
    public function save(string $name, SaveEmailTemplate $command, ?int $userId): EmailTemplateSourceView
    {
        $file = $this->file($name);
        $this->editing->check($file, $command->source);
        $template = $this->templates->find($file);
        if ($template === null) {
            $template = new EmailTemplate($file, $command->source, $this->clock->now(), $userId);
        } else {
            $template->change($command->source, $this->clock->now(), $userId);
        }
        $this->templates->save($template);

        return $this->template($name);
    }

    /**
     * Goes back to the theme's or the plugin's file.
     *
     * @throws EmailTemplateNotFound
     */
    public function reset(string $name): EmailTemplateSourceView
    {
        $template = $this->templates->find($this->file($name));
        if ($template !== null) {
            $this->templates->remove($template);
        }

        return $this->template($name);
    }

    /**
     * @throws EmailTemplateNotFound
     * @throws TemplateRejected
     * @throws EmailRuleViolation for an order email when the shop has no order to show it with
     * @throws EmailNotWritten    for a template that fails as it renders, as with a variable it is not given
     */
    public function preview(string $name, PreviewEmail $command): EmailPreviewView
    {
        $email = $this->write($name, $command);

        return new EmailPreviewView($email->to, $email->subject, $email->html, $email->text);
    }

    /**
     * Sends the preview to whoever asks, with "Test:" before its subject.
     *
     * @throws EmailTemplateNotFound
     * @throws TemplateRejected
     * @throws EmailRuleViolation
     * @throws EmailNotWritten
     */
    public function sendTest(string $name, PreviewEmail $command, int $userId): TestEmailView
    {
        $to = $this->site->userEmail($userId) ?? throw new EmailRuleViolation('Your user has no email address to send the test to.', 'orillacart_no_email');
        $email = $this->write($name, $command);
        $test = new Email($to, 'Test: ' . $email->subject, $email->html, $email->text, $email->fromName, $email->fromAddress, null, $email->kind, $email->orderNumber, $email->attachments);

        return new TestEmailView($to, $this->mailer->send($test));
    }

    /**
     * @throws EmailTemplateNotFound
     * @throws TemplateRejected
     * @throws EmailRuleViolation
     * @throws EmailNotWritten
     */
    private function write(string $name, PreviewEmail $command): Email
    {
        $file = $this->file($name);
        $drafts = [];
        if ($command->source !== null) {
            $this->editing->check($file, $command->source);
            $drafts[$file] = $command->source;
        }
        $kind = $command->kind ?? self::previews($name);

        return $this->editing->previewing($drafts, fn(): Email => $this->writer->previewing(fn(): Email => $this->email($kind, $command->order)));
    }

    /**
     * @throws EmailRuleViolation
     * @throws EmailNotWritten
     */
    private function email(string $kind, ?int $orderId): Email
    {
        $to = $this->writer->shopAddress();
        $url = $this->site->url();

        return match ($kind) {
            'account_confirm' => $this->writer->write('emails/account-confirm.html.twig', $kind, $to, null, ['first_name' => 'Ada', 'link' => $url, 'until' => $this->clock->now()->modify('+1 day')->format(\DATE_ATOM)]),
            'account_exists' => $this->writer->write('emails/account-exists.html.twig', $kind, $to, null, ['login_url' => $url, 'lost_password_url' => $url]),
            EmailKind::AccountWelcome->value => $this->writer->write(EmailKind::AccountWelcome->template(), $kind, $to, null, ['first_name' => 'Ada', 'account_url' => $url]),
            EmailKind::LowStock->value => $this->lowStock->preview(),
            EmailKind::CartReminder->value => $this->cartReminders->preview($to),
            EmailKind::GiftCard->value => $this->writer->write(EmailKind::GiftCard->template(), $kind, $to, null, ['card' => ['code' => 'ABCD-EFGH-JKLM-NPQR', 'amount' => '50', 'currency' => 'EUR', 'expires_at' => $this->clock->now()->modify('+1 year')->format('Y-m-d'), 'recipient_name' => 'Ada', 'message' => 'Happy birthday!', 'shop_url' => $url]]),
            EmailKind::ExemptionReceived->value, EmailKind::ExemptionReviewed->value, EmailKind::ExemptionExpiring->value => $this->writer->write(EmailKind::from($kind)->template(), $kind, $to, null, [
                'customer' => ['name' => 'Ada Lovelace', 'email' => $to],
                'first_name' => 'Ada',
                'account_url' => $url,
                'exemption' => ['certificate' => 'TX-RESALE-12345', 'regions' => ['US-TX'], 'expires_on' => $this->clock->now()->modify('+30 days')->format('Y-m-d'), 'status' => 'approved', 'refusal' => null, 'reason' => 'resale'],
            ]),
            EmailKind::BackInStock->value => $this->writer->write(EmailKind::BackInStock->template(), $kind, $to, null, ['product' => ['name' => 'Desk lamp', 'variation' => 'Green', 'url' => $url]]),
            default => $this->orderEmail(EmailKind::from($kind), $orderId),
        };
    }

    /**
     * @throws EmailRuleViolation
     * @throws EmailNotWritten
     */
    private function orderEmail(EmailKind $kind, ?int $orderId): Email
    {
        $orderId ??= $this->orders->orders(new OrderQuery(perPage: 1), forShop: true)->orders[0]->number ?? null;
        if ($orderId === null) {
            throw new EmailRuleViolation('Order emails show with an order: the shop has none yet.', 'orillacart_no_order_to_preview');
        }
        try {
            return $this->orderEmails->preview($orderId, $kind);
        } catch (OrderNotFound $e) {
            throw new EmailRuleViolation($e->text, 'orillacart_no_order_to_preview', $e);
        }
    }

    /**
     * @throws EmailTemplateNotFound
     */
    private function file(string $name): string
    {
        $file = 'emails/' . $name . '.html.twig';
        if (!\in_array($file, $this->editing->names(), true)) {
            throw new EmailTemplateNotFound($name);
        }

        return $file;
    }

    private static function shortName(string $file): string
    {
        return substr($file, \strlen('emails/'), -\strlen('.html.twig'));
    }

    /** The email a template shows in: its own, or the order confirmation for the frame and the parts. */
    private static function previews(string $name): string
    {
        return self::ACCOUNT_EMAILS[$name] ?? (EmailKind::tryFrom(str_replace('-', '_', $name)) ?? EmailKind::OrderProcessing)->value;
    }
}
