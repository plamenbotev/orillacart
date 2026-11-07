<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress;

use OrillaCart\Packages\Application\HtmlCleaner;

final readonly class KsesHtmlCleaner implements HtmlCleaner
{
    public function clean(string $html): string
    {
        return wp_kses_post($html);
    }
}
