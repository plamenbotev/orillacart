<?php

declare(strict_types=1);

namespace OrillaCart\Extension;

/**
 * The provider could not be reached or refused the request. The message is for the shop's log; customers see a
 * general one.
 */
final class ProviderUnavailable extends \RuntimeException
{
    public function __construct(
        public readonly string $provider,
        string $detail,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('%s: %s', $provider, $detail), 0, $previous);
    }
}
