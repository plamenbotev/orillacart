<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use OrillaCart\Invoicing\Application\Dto\InvoiceDocument;
use OrillaCart\Invoicing\Application\InvoicePdfs;
use OrillaCart\Platform\CacheFolders;
use OrillaCart\Platform\PluginContext;
use OrillaCart\Shared\Lifecycle\Languages;
use OrillaCart\Shared\View\TemplateRenderer;

/**
 * Prints the invoice page (templates/invoices/document.html.twig, which a theme may override) with dompdf, on A4,
 * in DejaVu Sans so every alphabet prints, with only the letters used embedded. Nothing is fetched: images and
 * styles come in the page, or not at all.
 */
final readonly class DompdfInvoicePdfs implements InvoicePdfs
{
    public function __construct(
        private TemplateRenderer $templates,
        private PluginContext $context,
        private Languages $languages,
    ) {}

    public function pdf(InvoiceDocument $document): string
    {
        $html = $this->templates->render('invoices/document.html.twig', ['document' => $document, 'locale' => str_replace('_', '-', $this->languages->current()), 'pdf' => true]);
        $temp = \function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
        $options = new Options()
            ->setIsRemoteEnabled(false)
            ->setIsJavascriptEnabled(false)
            ->setIsPhpEnabled(false)
            ->setDefaultFont('DejaVu Sans')
            ->setDefaultPaperSize('a4')
            ->setTempDir($temp)
            ->setFontCache(CacheFolders::path('pdf-fonts', $this->context->build) ?? $temp)
            ->setChroot([$this->context->path('')]);
        // A page takes some 20 MB to print, as editing an image does: ask for what WordPress allows such work.
        if (\function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('orillacart_pdf');
        }
        $pdf = new Dompdf($options);
        try {
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->render();

            return $pdf->output() ?? throw new \RuntimeException('dompdf printed nothing.');
        } finally {
            // Its frames refer to each other: free them now, not when PHP next collects, as a worker prints many.
            unset($pdf);
            gc_collect_cycles();
        }
    }
}
