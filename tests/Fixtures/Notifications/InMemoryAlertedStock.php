<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Notifications;

use OrillaCart\Notifications\Domain\AlertedStock;

final class InMemoryAlertedStock implements AlertedStock
{
    /** @var list<string> */
    public array $keys = [];

    public function keys(): array
    {
        return $this->keys;
    }

    public function remember(array $keys): void
    {
        $this->keys = $keys;
    }
}
