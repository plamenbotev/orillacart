<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress;

use OrillaCart\Shared\Users\UserNames;

final readonly class WordPressUserNames implements UserNames
{
    public function names(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach (get_users(['include' => array_values(array_unique($ids)), 'fields' => ['ID', 'display_name']]) as $user) {
            if (\is_object($user) && isset($user->ID, $user->display_name) && is_numeric($user->ID) && \is_string($user->display_name)) {
                $names[(int) $user->ID] = $user->display_name;
            }
        }

        return $names;
    }
}
