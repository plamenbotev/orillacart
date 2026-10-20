<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Notifications;

use OrillaCart\Notifications\Application\Site;

final class FakeSite implements Site
{
    public string $name = 'Lamp Shop';

    public function name(): string
    {
        return $this->name;
    }

    public function adminEmail(): string
    {
        return 'owner@shop.test';
    }

    public function url(): string
    {
        return 'https://shop.test/';
    }

    public function userEmail(int $userId): ?string
    {
        return $userId === 7 ? 'manager@shop.test' : null;
    }
}
