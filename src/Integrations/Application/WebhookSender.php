<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Application;

use OrillaCart\Integrations\Application\Dto\WebhookResponse;

/** Posts a webhook's body: WordPress's HTTP API in production. */
interface WebhookSender
{
    /**
     * @param array<string, string> $headers
     */
    public function post(string $url, string $body, array $headers): WebhookResponse;
}
