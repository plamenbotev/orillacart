<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Admin;

use OrillaCart\Platform\WordPress\Security\SiteSignatures;
use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;

/**
 * Whether the key the shop seals its credentials with (payment providers' keys, the search password) lives
 * outside the database: in wp-config.php's salts, or in ORILLACART_SECRETS_KEY. Salts kept in the database put
 * the key next to what it seals, so a copy of the database opens every credential.
 */
final readonly class SecretsHealth implements HealthCheck
{
    public static function id(): string
    {
        return 'secrets_key';
    }

    public static function label(): string
    {
        return __('The key of the shop\'s credentials', 'orillacart');
    }

    public function check(): HealthStatus
    {
        if (SiteSignatures::secretsKey() !== null) {
            return HealthStatus::good(__('The shop seals its credentials with ORILLACART_SECRETS_KEY', 'orillacart'));
        }
        if (!SiteSignatures::saltsInDatabase()) {
            return HealthStatus::good(__('The shop seals its credentials with the salts in wp-config.php', 'orillacart'));
        }

        return HealthStatus::recommended(
            __('The salts the shop seals its credentials with are kept in the database', 'orillacart'),
            __('AUTH_KEY or AUTH_SALT is not set in wp-config.php (or is the sample phrase), so WordPress keeps them in the database, and a copy of the database opens the payment providers\' keys and other credentials the shop keeps; a plugin that changes the salts there loses them. Set the salts in wp-config.php, or define(\'ORILLACART_SECRETS_KEY\', \'a long random phrase\'); and save each credential again (or run wp orillacart doctor --fix).', 'orillacart'),
        );
    }

    public function info(): array
    {
        return [
            'own_key' => ['label' => __('ORILLACART_SECRETS_KEY defined', 'orillacart'), 'value' => SiteSignatures::secretsKey() !== null ? __('yes', 'orillacart') : __('no', 'orillacart')],
            'salts_in_database' => ['label' => __('Salts kept in the database', 'orillacart'), 'value' => SiteSignatures::saltsInDatabase() ? __('yes', 'orillacart') : __('no', 'orillacart')],
        ];
    }
}
