<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application\Dto;

use OrillaCart\Returns\Domain\ItemReturn;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the shop approves a return, with how to send the items, or refuses it, saying why.
 */
final readonly class AnswerReturn
{
    public function __construct(
        #[Assert\Length(max: ItemReturn::MAX_ANSWER_LENGTH)]
        public string $answer = '',
    ) {}
}
