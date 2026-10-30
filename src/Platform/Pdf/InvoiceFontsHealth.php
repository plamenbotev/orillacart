<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Pdf;

use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;

/**
 * Whether the invoices printed to PDF had Chinese, Japanese or Korean letters with no font to print them: DejaVu
 * Sans, which the PDFs use, has none, so they printed as empty boxes (DompdfInvoicePdfs).
 */
final readonly class InvoiceFontsHealth implements HealthCheck
{
    public function __construct(private InvoiceFonts $fonts) {}

    public static function id(): string
    {
        return 'invoice_fonts';
    }

    public static function label(): string
    {
        return __('Letters in the invoice PDFs', 'orillacart');
    }

    public function check(): HealthStatus
    {
        if ($this->fonts->missingSince() === null || $this->fonts->cjk() !== []) {
            return HealthStatus::good(__('The invoice PDFs have a font for every letter they printed', 'orillacart'));
        }

        return HealthStatus::recommended(
            __('Invoice PDFs printed Chinese, Japanese or Korean letters as empty boxes', 'orillacart'),
            __('The PDFs attached to emails print in DejaVu Sans, which has no Chinese, Japanese or Korean letters. Put a TrueType font that has them (such as Noto Sans SC, JP or KR as .ttf) on the server and name it with the orillacart_invoice_fonts filter: add_filter(\'orillacart_invoice_fonts\', fn() => [[\'family\' => \'Noto Sans SC\', \'normal\' => \'/path/NotoSansSC-Regular.ttf\', \'bold\' => \'/path/NotoSansSC-Bold.ttf\']]);', 'orillacart'),
        );
    }

    public function info(): array
    {
        $fonts = array_column($this->fonts->cjk(), 'family');

        return ['invoice_fonts' => ['label' => __('Fonts for CJK letters in invoice PDFs', 'orillacart'), 'value' => $fonts === [] ? __('none', 'orillacart') : implode(', ', $fonts)]];
    }
}
