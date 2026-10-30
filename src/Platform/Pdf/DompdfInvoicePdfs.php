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
 * in DejaVu Sans so every alphabet but Chinese, Japanese and Korean prints, with only the letters used embedded.
 * Nothing is fetched: images and styles come in the page, or not at all.
 *
 * DejaVu has no CJK letters, and a CJK font is too large to ship (some 16 MB a weight). A site that sells in those
 * languages names one with the `orillacart_invoice_fonts` filter (FilteredInvoiceFonts). Dompdf takes one font for
 * a run of text, never a letter from another, so a page with CJK letters prints in the first such font, which
 * carries Latin letters too. A page printed with CJK letters and no such font shows in Site Health
 * (InvoiceFontsHealth).
 *
 * Right-to-left text (Arabic, Hebrew) is marked dir="rtl" run by run; dompdf neither shapes Arabic letters nor
 * reorders mixed text, so Arabic prints as separate letters: such shops send the HTML invoice, or an add-on's
 * InvoicePdfs, instead.
 */
final readonly class DompdfInvoicePdfs implements InvoicePdfs
{
    /** Chinese, Japanese and Korean letters, and their punctuation. */
    public const string CJK = '/[\x{3000}-\x{303F}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}\x{FF00}-\x{FFEF}]/u';

    public function __construct(
        private TemplateRenderer $templates,
        private PluginContext $context,
        private Languages $languages,
        private InvoiceFonts $fonts,
    ) {}

    public function pdf(InvoiceDocument $document): string
    {
        $context = ['document' => $document, 'locale' => str_replace('_', '-', $this->languages->current()), 'pdf' => true, 'pdf_fonts' => []];
        $html = $this->templates->render('invoices/document.html.twig', $context);
        $fonts = [];
        if (preg_match(self::CJK, strip_tags($html)) === 1) {
            $fonts = $this->fonts->cjk();
            $this->fonts->printedCjk($fonts !== []);
            if ($fonts !== []) {
                $html = $this->templates->render('invoices/document.html.twig', ['pdf_fonts' => array_column($fonts, 'family')] + $context);
            }
        }
        $temp = \function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
        $cache = CacheFolders::path('pdf-fonts', $this->context->build) ?? $temp;
        $options = new Options()
            ->setIsRemoteEnabled(false)
            ->setIsJavascriptEnabled(false)
            ->setIsPhpEnabled(false)
            ->setDefaultFont('DejaVu Sans')
            ->setDefaultPaperSize('a4')
            ->setTempDir($temp)
            ->setFontCache($cache)
            ->setChroot([$this->context->path(''), ...array_values(array_unique(array_merge(...array_map(static fn(array $font): array => array_map(dirname(...), array_values(array_filter([$font['normal'], $font['bold']], static fn(?string $file): bool => $file !== null))), $fonts))))]);
        if ($fonts !== []) {
            // The fonts named are copied in with their metrics: to the shop's cache, never into the plugin's folder.
            $options->setFontDir($cache);
        }
        // A page takes some 20 MB to print, as editing an image does: ask for what WordPress allows such work.
        if (\function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('orillacart_pdf');
        }
        $pdf = new Dompdf($options);
        try {
            foreach ($fonts as $font) {
                $pdf->getFontMetrics()->registerFont(['family' => $font['family'], 'weight' => 'normal', 'style' => 'normal'], $font['normal']);
                $pdf->getFontMetrics()->registerFont(['family' => $font['family'], 'weight' => 'bold', 'style' => 'normal'], $font['bold'] ?? $font['normal']);
            }
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
