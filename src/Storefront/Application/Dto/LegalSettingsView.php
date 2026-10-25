<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application\Dto;

use OrillaCart\Storefront\Domain\LegalSettings;
use OrillaCart\Storefront\Domain\OrderButton;

final readonly class LegalSettingsView
{
    /** @param list<string> $orderButtons */
    public function __construct(
        public ?int $terms,
        public ?int $withdrawal,
        public ?int $delivery,
        public ?int $legalNotice,
        public bool $acceptTerms,
        public string $orderButton,
        public array $orderButtons,
    ) {}

    public static function fromSettings(LegalSettings $settings): self
    {
        return new self($settings->terms, $settings->withdrawal, $settings->delivery, $settings->legalNotice, $settings->acceptTerms, $settings->orderButton->value, OrderButton::values());
    }
}
