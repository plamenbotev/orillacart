<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Customers\Application\Accounts;
use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Forms\Domain\CleanText;
use OrillaCart\Forms\Domain\FormRuleViolation;
use OrillaCart\Forms\Domain\UploadInspector;
use OrillaCart\Shared\Domain\InvalidFields;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Files\PrivateFiles;
use OrillaCart\Tax\Application\Dto\AccountTaxExemptionView;
use OrillaCart\Tax\Application\Dto\SendTaxExemption;
use OrillaCart\Tax\Application\Dto\TaxExemptionView;
use OrillaCart\Tax\Domain\ExemptionReason;
use OrillaCart\Tax\Domain\ExemptionStatus;
use OrillaCart\Tax\Domain\TaxExemption;
use OrillaCart\Tax\Domain\TaxExemptionRepository;
use OrillaCart\Tax\Domain\TaxSettingsRepository;
use Psr\Clock\ClockInterface;

/**
 * Certificates customers send, with a copy of each, kept in the shop's private files: the shop checks them and
 * approves or refuses, and the customer hears which, and again before an approved one runs out. Until the shop
 * approves one, the customer's orders are taxed.
 */
final readonly class ExemptionCertificates
{
    public const int MAX_MEGABYTES = 10;
    /** How many a customer may have waiting at once. */
    public const int MAX_PENDING = 3;
    /** How long before its last day the customer is reminded. */
    public const int REMIND_DAYS = 30;
    private const array EXTENSIONS = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private TaxExemptionRepository $exemptions,
        private TaxSettingsRepository $settings,
        private Accounts $accounts,
        private PrivateFiles $files,
        private UploadInspector $inspector,
        private ExemptionMail $mail,
        private ClockInterface $clock,
    ) {}

    public function accepts(): bool
    {
        return $this->settings->load()->acceptsCertificates;
    }

    /** @return list<AccountTaxExemptionView> the customer's own, newest first */
    public function mine(int $customerId): array
    {
        return array_map(static fn(TaxExemption $exemption): AccountTaxExemptionView => AccountTaxExemptionView::fromView(TaxExemptionView::fromExemption($exemption)), $this->exemptions->ofCustomer($customerId));
    }

    /**
     * Keeps the certificate a customer sent, waiting for the shop; the upload is gone afterwards, kept or not.
     *
     * @param string $filePath where the upload of its copy waits, a temporary file
     * @param string $fileName the name the copy came with
     *
     * @throws RuleViolation when the shop takes no certificates, or the customer has as many waiting as may be
     * @throws InvalidFields for a copy that is not a PDF or picture, or too large
     * @throws CustomerNotFound
     */
    public function send(int $customerId, SendTaxExemption $command, string $filePath, string $fileName): AccountTaxExemptionView
    {
        $clean = null;
        try {
            if (!$this->accepts()) {
                throw new RuleViolation('The shop takes no exemption certificates online. Contact it instead.', 'orillacart_certificates_closed');
            }
            $customer = $this->accounts->find($customerId) ?? throw new CustomerNotFound($customerId);
            $mine = $this->exemptions->ofCustomer($customerId);
            if (\count(array_filter($mine, static fn(TaxExemption $exemption): bool => $exemption->status() === ExemptionStatus::Pending)) >= self::MAX_PENDING) {
                throw new RuleViolation('The shop is still checking the certificates you sent. Wait for its answer.', 'orillacart_certificates_waiting');
            }
            $exemption = new TaxExemption(
                $customerId,
                ExemptionReason::from($command->reason),
                $command->certificate,
                array_values(array_unique(array_map(strtoupper(...), $command->regions))),
                $command->expiresOn === null ? null : new \DateTimeImmutable($command->expiresOn),
                '',
                $this->clock->now(),
            );
            if ($exemption->expiresOn() !== null && $exemption->expiresOn()->format('Y-m-d') < $this->clock->now()->format('Y-m-d')) {
                throw new InvalidFields([['field' => 'expires_on', 'message' => 'The certificate ran out already.']]);
            }
            $size = is_file($filePath) ? filesize($filePath) : false;
            if ($size === false || $size === 0) {
                throw self::refused('Add a copy of the certificate: a PDF or a photo.');
            }
            if ($size > self::MAX_MEGABYTES * 1024 * 1024) {
                throw self::refused(\sprintf('The file is larger than %d MB.', self::MAX_MEGABYTES));
            }
            $type = $this->inspector->type($filePath);
            $takes = $this->inspector->takesImages() ? array_keys(self::EXTENSIONS) : ['application/pdf'];
            if ($type === null || !\in_array($type, $takes, true)) {
                throw self::refused($this->inspector->takesImages() ? 'Send a PDF or a photo (JPEG, PNG, WebP).' : 'Send a PDF.');
            }
            try {
                $clean = $this->inspector->clean($filePath, $type);
            } catch (FormRuleViolation $e) {
                throw self::refused($e->getMessage());
            }
            if ((int) filesize($clean) > 2 * self::MAX_MEGABYTES * 1024 * 1024) {
                throw self::refused(\sprintf('The file is larger than %d MB.', self::MAX_MEGABYTES));
            }
            $name = (string) preg_replace('/\.[^.]*$/', '', CleanText::filename($fileName));
            $name = ($name === '' ? 'certificate' : $name) . '.' . self::EXTENSIONS[$type];
            $stored = $this->files->store($clean, $name);
            $clean = null;
            $exemption->sentByCustomer($stored->key, $name, $type);
            $this->exemptions->save($exemption);
            $view = TaxExemptionView::fromExemption($exemption);
            $this->mail->received($view, trim("{$customer->firstName} {$customer->lastName}"), $customer->email);

            return AccountTaxExemptionView::fromView($view);
        } finally {
            foreach ([$filePath, $clean] as $path) {
                if ($path !== null && is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /**
     * @throws RuleViolation for an exemption that is not the customer's, or one checked already
     */
    public function approve(int $customerId, int $exemptionId): TaxExemptionView
    {
        $exemption = $this->exemption($customerId, $exemptionId);
        $exemption->approve($this->clock->now());

        return $this->reviewed($exemption);
    }

    /**
     * @throws RuleViolation for an exemption that is not the customer's, one checked already, or no reason
     */
    public function refuse(int $customerId, int $exemptionId, string $reason): TaxExemptionView
    {
        $exemption = $this->exemption($customerId, $exemptionId);
        $exemption->refuse($reason, $this->clock->now());

        return $this->reviewed($exemption);
    }

    /**
     * The copy of a customer's certificate, for the shop.
     *
     * @return array{path: string, name: string, type: string}|null
     */
    public function file(int $customerId, int $exemptionId): ?array
    {
        $exemption = $this->exemptions->find($exemptionId);
        $file = $exemption?->customerId() === $customerId ? $exemption->file() : null;
        $path = $file === null ? null : $this->files->path($file['key']);

        return $file === null || $path === null ? null : ['path' => $path, 'name' => $file['name'], 'type' => $file['type']];
    }

    /**
     * Takes an exemption off the customer, with its copy.
     *
     * @throws RuleViolation for an exemption that is not the customer's
     */
    public function remove(int $customerId, int $exemptionId): void
    {
        $exemption = $this->exemption($customerId, $exemptionId);
        $this->forgetFile($exemption);
        $this->exemptions->remove($exemption);
    }

    /** The copies of a customer's certificates go with their account. */
    public function forgetCustomer(int $customerId): void
    {
        foreach ($this->exemptions->ofCustomer($customerId) as $exemption) {
            $this->forgetFile($exemption);
        }
        $this->exemptions->removeOfCustomer($customerId);
    }

    /**
     * Tells customers their approved certificates run out within REMIND_DAYS, once each.
     *
     * @return int how many were told
     */
    public function remindExpiring(int $limit = 100): int
    {
        $today = $this->clock->now();
        $told = 0;
        foreach ($this->exemptions->expiringBetween($today, $today->modify('+' . self::REMIND_DAYS . ' days'), $limit) as $exemption) {
            $customer = $this->accounts->find($exemption->customerId());
            if ($customer !== null) {
                $this->mail->expiring(TaxExemptionView::fromExemption($exemption), $customer->email, $customer->firstName);
                ++$told;
            }
            $exemption->reminded($today);
            $this->exemptions->save($exemption);
        }

        return $told;
    }

    private function reviewed(TaxExemption $exemption): TaxExemptionView
    {
        $this->exemptions->save($exemption);
        $view = TaxExemptionView::fromExemption($exemption);
        $customer = $this->accounts->find($exemption->customerId());
        if ($customer !== null) {
            $this->mail->reviewed($view, $customer->email, $customer->firstName);
        }

        return $view;
    }

    /**
     * @throws RuleViolation
     */
    private function exemption(int $customerId, int $exemptionId): TaxExemption
    {
        $exemption = $this->exemptions->find($exemptionId);
        if ($exemption === null || $exemption->customerId() !== $customerId) {
            throw new RuleViolation('The customer has no such exemption.', 'orillacart_unknown_exemption');
        }

        return $exemption;
    }

    private function forgetFile(TaxExemption $exemption): void
    {
        $file = $exemption->file();
        if ($file !== null) {
            $this->files->delete($file['key']);
        }
    }

    private static function refused(string $message): InvalidFields
    {
        return new InvalidFields([['field' => 'file', 'message' => $message]]);
    }
}
