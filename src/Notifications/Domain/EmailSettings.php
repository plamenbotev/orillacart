<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

/**
 * Who the shop's emails come from, where the shop's own emails go, and which emails are turned off.
 * Without a name or address of its own, the shop uses the site's name and administration email.
 */
final readonly class EmailSettings
{
    /**
     * @param list<EmailKind> $disabled
     */
    public function __construct(
        public ?string $fromName = null,
        public ?string $fromAddress = null,
        public ?string $shopAddress = null,
        public array $disabled = [],
    ) {
        foreach (['sender' => $fromAddress, 'shop' => $shopAddress] as $which => $address) {
            if ($address !== null && filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuleViolation(\sprintf('The %s address "%s" is not an email address.', $which, $address), 'orillacart_invalid_email');
            }
        }
        if ($fromName !== null && (trim($fromName) === '' || mb_strlen($fromName) > 100 || preg_match('/[\r\n<>"]/', $fromName) === 1)) {
            throw new RuleViolation('The sender name has up to 100 characters, without line breaks, quotes or angle brackets.', 'orillacart_invalid_sender_name');
        }
    }

    public static function defaults(): self
    {
        return new self();
    }

    public function sends(EmailKind $kind): bool
    {
        return !\in_array($kind, $this->disabled, true);
    }
}
