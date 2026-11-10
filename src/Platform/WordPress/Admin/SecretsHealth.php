<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Admin;

use OrillaCart\Platform\Security\SealedValues;
use OrillaCart\Platform\WordPress\Security\SiteSignatures;
use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;

/**
 * Whether the key the shop seals its credentials with (payment providers' keys, the search password) lives
 * outside the database: in wp-config.php's salts, or in ORILLACART_SECRETS_KEY. Salts kept in the database put
 * the key next to what it seals, so a copy of the database opens every credential. Defining the key is half of
 * it: what was sealed before stays sealed with the salts until it is sealed again, so the check is good only once
 * nothing is left under an earlier key.
 */
final readonly class SecretsHealth implements HealthCheck
{
    public const string STALE = 'stale';
    public const string OWN_KEY = 'own_key';
    public const string SALTS = 'salts';
    public const string DATABASE = 'database';

    public function __construct(private SealedValues $sealed) {}

    public static function id(): string
    {
        return 'secrets_key';
    }

    public static function label(): string
    {
        return __('The key of the shop\'s credentials', 'orillacart');
    }

    /**
     * What the check finds: credentials still under an earlier key (or never sealed) come first, whichever key is
     * the current one, since they are what a copy of the database opens.
     *
     * @param int $stale how many sealed values are not sealed with the current key
     */
    public static function finding(bool $ownKey, bool $saltsInDatabase, int $stale): string
    {
        if ($stale > 0) {
            return self::STALE;
        }
        if ($ownKey) {
            return self::OWN_KEY;
        }

        return $saltsInDatabase ? self::DATABASE : self::SALTS;
    }

    public function check(): HealthStatus
    {
        $ownKey = SiteSignatures::secretsKey() !== null;
        $stale = $this->stale();

        return match (self::finding($ownKey, SiteSignatures::saltsInDatabase(), $stale)) {
            self::STALE => HealthStatus::recommended(
                __('Some of the shop\'s credentials are not sealed with the current key', 'orillacart'),
                \sprintf(
                    /* translators: %d: how many credentials */
                    _n(
                        '%d credential the shop keeps is not sealed with the current key: it was sealed with the salts before ORILLACART_SECRETS_KEY was defined, or with an earlier key, or was never sealed, so a copy of the database (with the salts, when they are kept there) still opens it. Save it again, or run wp orillacart doctor --fix to seal everything with the current key.',
                        '%d credentials the shop keeps are not sealed with the current key: they were sealed with the salts before ORILLACART_SECRETS_KEY was defined, or with an earlier key, or were never sealed, so a copy of the database (with the salts, when they are kept there) still opens them. Save each again, or run wp orillacart doctor --fix to seal everything with the current key.',
                        $stale,
                        'orillacart',
                    ),
                    $stale,
                ),
            ),
            self::OWN_KEY => HealthStatus::good(__('The shop seals its credentials with ORILLACART_SECRETS_KEY', 'orillacart')),
            self::SALTS => HealthStatus::good(__('The shop seals its credentials with the salts in wp-config.php', 'orillacart')),
            default => HealthStatus::recommended(
                __('The salts the shop seals its credentials with are kept in the database', 'orillacart'),
                __('AUTH_KEY or AUTH_SALT is not set in wp-config.php (or is the sample phrase), so WordPress keeps them in the database, and a copy of the database opens the payment providers\' keys and other credentials the shop keeps; a plugin that changes the salts there loses them. Set the salts in wp-config.php, or define(\'ORILLACART_SECRETS_KEY\', \'a long random phrase\'); and save each credential again (or run wp orillacart doctor --fix).', 'orillacart'),
            ),
        };
    }

    public function info(): array
    {
        return [
            'own_key' => ['label' => __('ORILLACART_SECRETS_KEY defined', 'orillacart'), 'value' => SiteSignatures::secretsKey() !== null ? __('yes', 'orillacart') : __('no', 'orillacart')],
            'salts_in_database' => ['label' => __('Salts kept in the database', 'orillacart'), 'value' => SiteSignatures::saltsInDatabase() ? __('yes', 'orillacart') : __('no', 'orillacart')],
            'earlier_key' => ['label' => __('Credentials not sealed with the current key', 'orillacart'), 'value' => (string) $this->stale()],
        ];
    }

    /** How many sealed values an earlier key (or none) holds; none when the tables cannot be read, which the database check reports. */
    private function stale(): int
    {
        try {
            return $this->sealed->count()['stale'];
        } catch (\Throwable) {
            return 0;
        }
    }
}
