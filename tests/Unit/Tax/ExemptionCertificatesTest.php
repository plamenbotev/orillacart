<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Tax;

use OrillaCart\Forms\Infrastructure\SafeUploadInspector;
use OrillaCart\Shared\Domain\InvalidFields;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Tax\Application\Dto\ApproveTaxExemption;
use OrillaCart\Tax\Application\Dto\SendTaxExemption;
use OrillaCart\Tax\Application\ExemptionCertificates;
use OrillaCart\Tax\Application\TaxExemptions;
use OrillaCart\Tax\Domain\TaxBasis;
use OrillaCart\Tax\Domain\TaxLocation;
use OrillaCart\Tax\Domain\TaxSettings;
use OrillaCart\Tests\Fixtures\Customers\InMemoryAccounts;
use OrillaCart\Tests\Fixtures\Invoicing\SofiaCalendar;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxExemptionRepository;
use OrillaCart\Tests\Fixtures\Tax\InMemoryTaxSettingsRepository;
use OrillaCart\Tests\Fixtures\Tax\RecordingExemptionMail;
use OrillaCart\Tests\Fixtures\TemporaryPrivateFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A customer sends a certificate with its copy; it exempts nothing until the shop approves it, and the customer
 * hears what became of it, and again a month before it runs out.
 */
final class ExemptionCertificatesTest extends TestCase
{
    private const string PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n%%EOF\n";

    private ExemptionCertificates $certificates;
    private TaxExemptions $exemptions;
    private InMemoryTaxSettingsRepository $settings;
    private TemporaryPrivateFiles $files;
    private RecordingExemptionMail $mail;
    private MockClock $clock;
    private int $customer;

    protected function setUp(): void
    {
        $accounts = new InMemoryAccounts();
        $this->customer = $accounts->add('buyer@example.com', 'Grace');
        $this->clock = new MockClock('2026-10-23 10:00:00');
        $records = new InMemoryTaxExemptionRepository();
        $this->settings = new InMemoryTaxSettingsRepository();
        $this->settings->save(new TaxSettings(TaxBasis::ShippingAddress, null, acceptsCertificates: true));
        $this->files = new TemporaryPrivateFiles();
        $this->mail = new RecordingExemptionMail();
        $this->certificates = new ExemptionCertificates($records, $this->settings, $accounts, $this->files, new SafeUploadInspector(), $this->mail, $this->clock);
        $this->exemptions = new TaxExemptions($records, $accounts, $this->clock, new SofiaCalendar());
    }

    public function testASentCertificateExemptsOnceTheShopApprovesIt(): void
    {
        $sent = $this->send();
        self::assertSame(['pending', 'resale', ['US-TX']], [$sent->status, $sent->reason, $sent->regions]);
        self::assertSame([['kind' => 'received', 'to' => 'shop', 'certificate' => 'TX-1', 'status' => 'pending']], $this->mail->sent);
        self::assertNull($this->exemptions->covering($this->customer, new TaxLocation('US', 'TX')), 'not while the shop checks it');

        $file = $this->certificates->file($this->customer, $sent->id);
        self::assertNotNull($file);
        self::assertSame(['tax certificate.pdf', 'application/pdf', self::PDF], [$file['name'], $file['type'], file_get_contents($file['path'])]);
        self::assertNull($this->certificates->file($this->customer + 1, $sent->id), 'not another customer\'s');

        try {
            $this->certificates->approve($this->customer, $sent->id, new ApproveTaxExemption([]));
            self::fail('everywhere, EU VAT included, is not how a customer\'s certificate is taken');
        } catch (RuleViolation $e) {
            self::assertSame('orillacart_invalid_exemption', $e->errorCode);
        }
        $this->certificates->approve($this->customer, $sent->id, new ApproveTaxExemption(['us-tx'], '2027-12-31'));
        self::assertSame($sent->id, $this->exemptions->covering($this->customer, new TaxLocation('US', 'TX'))?->id);
        self::assertSame(['US-TX'], $this->certificates->mine($this->customer)[0]->regions, 'as the shop read it on the copy');
        self::assertSame(['received', 'reviewed'], array_column($this->mail->sent, 'kind'));
        self::assertSame(['shop', 'buyer@example.com'], array_column($this->mail->sent, 'to'));
        self::assertSame(['pending', 'approved'], array_column($this->mail->sent, 'status'));

        $this->expectException(RuleViolation::class);
        $this->certificates->refuse($this->customer, $sent->id, 'Too late');
    }

    public function testARefusedCertificateTellsTheCustomerWhyAndGoesWithItsCopy(): void
    {
        $sent = $this->send();
        $refused = $this->certificates->refuse($this->customer, $sent->id, 'The certificate is not signed.');
        self::assertSame(['refused', 'The certificate is not signed.'], [$refused->status, $refused->refusal]);
        self::assertSame(['refused', 'The certificate is not signed.'], [$this->certificates->mine($this->customer)[0]->status, $this->certificates->mine($this->customer)[0]->refusal]);
        self::assertNull($this->exemptions->covering($this->customer, new TaxLocation('US', 'TX')));

        $this->certificates->forgetCustomer($this->customer);
        self::assertCount(1, $this->files->deleted, 'the copy goes with the account');
        self::assertSame([], $this->certificates->mine($this->customer));
    }

    public function testRefusesWhatCannotBeACertificate(): void
    {
        foreach ([
            'not a PDF or picture' => fn() => $this->send(content: '<?php echo 1;'),
            'an empty file' => fn() => $this->send(content: ''),
            'run out already' => fn() => $this->send(expiresOn: '2026-01-01'),
            'too many waiting' => function (): void {
                $this->send();
                $this->send();
                $this->send();
                $this->send();
            },
            'the shop takes none' => function (): void {
                $this->settings->save(new TaxSettings(TaxBasis::ShippingAddress, null));
                $this->send();
            },
        ] as $case => $refused) {
            try {
                $refused();
                self::fail("{$case} was taken");
            } catch (RuleViolation|InvalidFields) {
            }
        }
        self::assertCount(3, $this->certificates->mine($this->customer));
        $left = glob(sys_get_temp_dir() . '/orillacart-test-certificate*');
        self::assertSame([], $left === false ? [] : $left, 'no upload is left behind');
    }

    public function testTheCustomerHearsOnceAMonthBeforeTheirCertificateRunsOut(): void
    {
        $soon = $this->send(expiresOn: '2026-11-20');
        $later = $this->send(expiresOn: '2027-06-30');
        $this->certificates->approve($this->customer, $soon->id, new ApproveTaxExemption(['US-TX'], '2026-11-20'));
        $this->certificates->approve($this->customer, $later->id, new ApproveTaxExemption(['US-TX'], '2027-06-30'));
        $this->mail->sent = [];

        self::assertSame(1, $this->certificates->remindExpiring());
        self::assertSame([['kind' => 'expiring', 'to' => 'buyer@example.com', 'certificate' => 'TX-1', 'status' => 'approved']], $this->mail->sent);
        self::assertSame(0, $this->certificates->remindExpiring(), 'once');
    }

    private function send(string $content = self::PDF, ?string $expiresOn = null): \OrillaCart\Tax\Application\Dto\AccountTaxExemptionView
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'orillacart-test-certificate');
        file_put_contents($path, $content);

        return $this->certificates->send($this->customer, new SendTaxExemption('resale', 'TX-1', ['us-tx'], $expiresOn), $path, 'tax certificate.PDF');
    }
}
