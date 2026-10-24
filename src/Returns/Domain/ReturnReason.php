<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Domain;

use OrillaCart\Shared\Domain\Text;

/** Why the customer sends items back. */
enum ReturnReason: string
{
    case Damaged = 'damaged';
    case WrongItem = 'wrong_item';
    case NotAsDescribed = 'not_as_described';
    case NoLongerNeeded = 'no_longer_needed';
    case Other = 'other';

    /** In the customer's words, translated where shown. */
    public function label(): Text
    {
        return new Text(match ($this) {
            self::Damaged => 'It arrived damaged',
            self::WrongItem => 'It is not what I ordered',
            self::NotAsDescribed => 'It is not as described',
            self::NoLongerNeeded => 'I no longer need it',
            self::Other => 'Another reason',
        });
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
