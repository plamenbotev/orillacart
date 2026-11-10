<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use OrillaCart\Customers\Domain\AccountMode;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateCustomerSettings
{
    public function __construct(
        #[Assert\Choice(callback: [AccountMode::class, 'values'])]
        public string $accounts,
        /** Null leaves it as it is. */
        public ?bool $guestOrders = null,
        /**
         * The roles whose users are the shop's customers; null leaves them as they are. The shop's own customer
         * role is always one, and a role that can manage the site or the shop never is.
         *
         * @var list<string>|null
         */
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 100)])]
        #[Assert\Count(max: 50)]
        public ?array $roles = null,
    ) {}
}
