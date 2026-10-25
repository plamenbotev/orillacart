<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

/**
 * The settings to try, as the form has them: a secret left empty is the one saved.
 */
final readonly class TestPaymentSettings
{
    /** @param array<string, string|bool> $settings by key */
    public function __construct(public array $settings = []) {}
}
