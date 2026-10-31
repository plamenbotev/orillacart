<?php

declare(strict_types=1);

namespace OrillaCart\Settings\Domain;

/**
 * What a deal that gave free items refunds when some of its items go back (two lamps bought and a third given,
 * one sent back): each item's share of what the deal's items cost together (20 of the 60); or, when what is kept
 * no longer earns a free item, its price kept from the refund, it comes back with them, or it stays the
 * customer's (the item sent back refunds what it cost).
 */
enum FreeItemsOnReturn: string
{
    case Spread = 'spread';
    case Charged = 'charged';
    case ComeBack = 'come_back';
    case StayFree = 'stay_free';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
