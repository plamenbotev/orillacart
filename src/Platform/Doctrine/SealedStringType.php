<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use OrillaCart\Shared\Security\Secrets;

/**
 * A string column whose value is sealed (Secrets), such as a webhook's signing secret: a copy of the database alone
 * does not give it away. One that cannot be opened (the salts changed) reads as empty, for the shop to make again.
 */
final class SealedStringType extends StringType
{
    public const string NAME = 'sealed_string';

    private static ?Secrets $secrets = null;

    public static function useSecrets(Secrets $secrets): void
    {
        self::$secrets = $secrets;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return \is_string($value) ? self::secrets()->seal($value) : null;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return \is_string($value) ? self::secrets()->open($value) ?? '' : null;
    }

    private static function secrets(): Secrets
    {
        return self::$secrets ?? throw new \LogicException('The sealed string type has no key: the entity manager sets it.');
    }
}
