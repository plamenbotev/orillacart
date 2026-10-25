<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Infrastructure\WordPress\Check;

use OrillaCart\Setup\Application\Dto\Readiness;
use OrillaCart\Setup\Application\ReadinessCheck;
use OrillaCart\Shared\Domain\Text;

/**
 * WordPress's privacy policy page, published (Settings → Privacy), where the shop's suggested text about what it
 * keeps goes too.
 */
final readonly class PrivacyReadiness implements ReadinessCheck
{
    public function check(): array
    {
        $page = get_option('wp_page_for_privacy_policy', 0);
        $page = is_numeric($page) ? (int) $page : 0;

        return $page > 0 && get_post_status($page) === 'publish'
            ? []
            : [Readiness::advice('privacy.page', Text::of('The site has no published privacy policy: choose or write one in Settings → Privacy; WordPress suggests what the shop keeps.'))];
    }
}
