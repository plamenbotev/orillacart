<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Infrastructure\WordPress\Hooks;

use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Invoicing\Domain\InvoiceNotFound;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * An invoice or credit note as a page of its own, laid out for printing (?orillacart_invoice=NUMBER&key=KEY):
 * the browser prints it or saves it as a PDF. The order's key, which only the customer and the shop have,
 * opens it. Themes can change it as templates/invoices/document.html.twig in their orillacart folder.
 */
#[AsHook('template_redirect', priority: 0)]
final readonly class ShowInvoice
{
    public const string QUERY = 'orillacart_invoice';
    public const string KEY = 'key';

    public function __construct(
        private Invoices $invoices,
        private TemplateRenderer $templates,
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
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        echo $this->templates->render('invoices/document.html.twig', ['document' => $document, 'locale' => str_replace('_', '-', get_locale())]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Twig escapes.
        exit;
    }
}
