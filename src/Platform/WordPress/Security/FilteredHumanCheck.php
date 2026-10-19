<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Security;

use OrillaCart\Shared\Http\Security\HumanCheck;

/**
 * Asks add-ons through the filter orillacart_verify_request: a WP_Error refuses with its message.
 */
final readonly class FilteredHumanCheck implements HumanCheck
{
    public function refusal(string $guard, array $body): ?string
    {
        $answer = apply_filters('orillacart_verify_request', true, $guard, $body);
        if ($answer instanceof \WP_Error) {
            return wp_strip_all_tags($answer->get_error_message());
        }

        return $answer === false ? 'We could not take this. Try again.' : null;
    }
}
