<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockDefinition;

/**
 * The seller's legal notice (Impressum), as invoices name them: it follows the invoice settings, so the page
 * never says other than the invoices do.
 */
final readonly class LegalNoticeBlock implements BlockDefinition
{
    public function __construct(
        private Invoices $invoices,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/legal-notice';
    }

    public function render(array $attributes): string
    {
        $seller = $this->invoices->settings();

        return $this->templates->render('storefront/legal-notice.html.twig', [
            'name' => $seller->legalName,
            'address' => array_values(array_filter(explode("\n", $seller->address), static fn(string $line): bool => trim($line) !== '')),
            'email' => $seller->email,
            'phone' => $seller->phone,
            'vat_number' => $seller->vatNumber,
            'company_number' => $seller->companyNumber,
        ]);
    }
}
