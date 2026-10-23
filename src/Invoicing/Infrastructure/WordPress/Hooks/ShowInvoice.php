<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Infrastructure\WordPress\Hooks;

use OrillaCart\Invoicing\Application\EInvoices;
use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Invoicing\Domain\InvoiceNotFound;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * An invoice or credit note as a page of its own, laid out for printing (?orillacart_invoice=NUMBER&receipt=…):
 * the browser prints it or saves it as a PDF; with &format=xml, the e-invoice as a download. A link to its order's receipt that still works opens it. Themes can change it as templates/invoices/document.html.twig in their orillacart folder.
 */
#[AsHook('template_redirect', priority: 0)]
final readonly class ShowInvoice
{
    public const string QUERY = 'orillacart_invoice';
    public const string KEY = 'receipt';
    /** &format=xml: the document as an e-invoice, when the shop makes them. */
    public const string FORMAT = 'format';

    public function __construct(
        private Invoices $invoices,
        private TemplateRenderer $templates,
        private EInvoices $eInvoices,
    ) {}

    public function __invoke(): void
    {
        $number = $_GET[self::QUERY] ?? null;
        $key = $_GET[self::KEY] ?? null;
        if (!\is_string($number)) {
            return;
        }
        try {
            $document = $this->invoices->document(sanitize_text_field(wp_unslash($number)), \is_string($key) ? sanitize_key($key) : '');
        } catch (InvoiceNotFound $e) {
            wp_die(esc_html($e->getMessage()), esc_html__('Invoice not found', 'orillacart'), ['response' => 404, 'back_link' => true]);
        }

        nocache_headers();
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        if (($_GET[self::FORMAT] ?? null) === 'xml') {
            if ($this->eInvoices->format() === null) {
                wp_die(esc_html__('The shop makes no e-invoices.', 'orillacart'), esc_html__('Invoice not found', 'orillacart'), ['response' => 404, 'back_link' => true]);
            }
            header('Content-Type: application/xml; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $this->eInvoices->filename($document)) . '"');
            echo $this->eInvoices->xml($document); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML built with DOMDocument, served as a download.
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->templates->render('invoices/document.html.twig', ['document' => $document, 'locale' => str_replace('_', '-', get_locale())]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig escapes.
        exit;
    }
}
