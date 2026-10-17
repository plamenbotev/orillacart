<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

use OrillaCart\Notifications\Domain\EmailKind;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: change who the shop's emails come from, where the shop's own go, and which are turned off.
 * Empty name or addresses use the site's.
 */
final readonly class UpdateEmailSettings
{
    /**
     * @param list<string> $disabled
     */
    public function __construct(
        #[Assert\Length(max: 100)]
        public ?string $fromName = null,
        #[Assert\Email]
        public ?string $fromAddress = null,
        #[Assert\Email]
        public ?string $shopAddress = null,
        #[Assert\All([new Assert\Choice(callback: [EmailKind::class, 'values'])])]
        public array $disabled = [],
    ) {}
}
