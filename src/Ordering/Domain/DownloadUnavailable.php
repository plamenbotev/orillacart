<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\Text;

final class DownloadUnavailable extends OrderRuleViolation
{
    /**
     * @param list<int|float|string|\Stringable> $values
     */
    public function __construct(string|Text $reason, array $values = [])
    {
        parent::__construct($reason, 'orillacart_download_unavailable', values: $values);
    }
}
