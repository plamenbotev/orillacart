<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;
use OrillaCart\Shared\Security\Secrets;

/**
 * A JSON column whose value is sealed (Secrets): stored as {"sealed": "…"}, still valid JSON, so the column is
 * an ordinary JSON one; read back as the array it was. A value never sealed reads as it is, and one that cannot
 * be opened (the salts changed) reads as empty, for the shop to enter again.
 */
final class SealedJsonType extends JsonType
{
    public const string NAME = 'sealed_json';

    private static ?Secrets $secrets = null;
    /** @var (\Closure(): void)|null told when a value cannot be opened, for the shop to hear of it */
    private static ?\Closure $unreadable = null;

    /**
     * @param (\Closure(): void)|null $unreadable
     */
    public static function useSecrets(Secrets $secrets, ?\Closure $unreadable = null): void
    {
        self::$secrets = $secrets;
        self::$unreadable = $unreadable;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        $json = parent::convertToDatabaseValue($value, $platform);
        if ($json === null) {
            return null;
        }

        return json_encode(['sealed' => self::secrets()->seal($json)], \JSON_THROW_ON_ERROR);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        $decoded = parent::convertToPHPValue($value, $platform);
        if (!\is_array($decoded) || array_keys($decoded) !== ['sealed'] || !\is_string($decoded['sealed'])) {
            return $decoded;
        }
        $json = self::secrets()->open($decoded['sealed']);
        if ($json === null) {
            self::$unreadable?->__invoke();

            return [];
        }

        return parent::convertToPHPValue($json, $platform);
    }

    private static function secrets(): Secrets
    {
        return self::$secrets ?? throw new \LogicException('The sealed JSON type has no key: the entity manager sets it.');
    }
}
