<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

final class DownloadUnavailable extends OrderRuleViolation
{
    public function __construct(string $reason)
    {
        parent::__construct($reason, 'orillacart_download_unavailable');
    }
}
