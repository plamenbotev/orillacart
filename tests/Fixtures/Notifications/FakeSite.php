<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Notifications;

use OrillaCart\Notifications\Application\Site;

final class FakeSite implements Site
{
    public function name(): string
    {
        return 'Lamp Shop';
    }

    public function adminEmail(): string
    {
        return 'owner@shop.test';
    }

    public function url(): string
    {
        return 'https://shop.test/';
    }
}
