<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Infrastructure\WordPress;

use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use OrillaCart\Tax\Domain\ImportRegistration;
use OrillaCart\Tax\Domain\ImportRegistrationsRepository;
use OrillaCart\Tax\Domain\ImportScheme;
use Psr\Log\LoggerInterface;

/**
 * Keeps the import schemes the shop sells under in one WordPress option, read at checkout.
 */
final readonly class OptionImportRegistrationsRepository implements ImportRegistrationsRepository, LifecycleListener, RemovesShopData
{
    public const string OPTION = 'orillacart_import_schemes';

    public function __construct(private LoggerInterface $logger) {}

    public function load(): array
    {
        $stored = get_option(self::OPTION);
        if (!\is_array($stored)) {
            return [];
        }
        $registrations = [];
        foreach ($stored as $entry) {
            $scheme = \is_array($entry) && \is_string($entry['scheme'] ?? null) ? ImportScheme::tryFrom($entry['scheme']) : null;
            $number = \is_array($entry) ? ($entry['number'] ?? null) : null;
            $limit = \is_array($entry) ? ($entry['limit'] ?? null) : null;
            try {
                if ($scheme === null || ($number !== null && !\is_string($number)) || !\is_string($limit)) {
                    throw new RuleViolation('Not a registration.');
                }
                $registrations[$scheme->value] = new ImportRegistration($scheme, $number, $limit);
            } catch (RuleViolation) {
                $this->logger->warning('A stored import scheme is not valid and is left out.');
            }
        }

        return $registrations;
    }

    public function save(array $registrations): void
    {
        update_option(self::OPTION, array_values(array_map(static fn(ImportRegistration $registration): array => [
            'scheme' => $registration->scheme->value,
            'number' => $registration->number,
            'limit' => $registration->limit,
        ], $registrations)), true);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
