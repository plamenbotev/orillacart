<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Returns;

use OrillaCart\Returns\Application\WithdrawalLinks;

final class FakeWithdrawalLinks implements WithdrawalLinks
{
    public function url(#[\SensitiveParameter] string $token): string
    {
        return 'https://shop.test/withdraw?orillacart_withdrawal=' . rawurlencode($token);
    }
}
