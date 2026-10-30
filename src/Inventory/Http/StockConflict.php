<?php

declare(strict_types=1);

namespace OrillaCart\Inventory\Http;

use OrillaCart\Inventory\Application\StockChangedMeanwhile;
use OrillaCart\Shared\Http\HttpException;

/**
 * A stock save refused because levels moved since they were loaded: 409 orillacart_stock_changed, with each such
 * level as it is now under data.changed (quantity null: the stockroom holds none of it).
 */
final class StockConflict
{
    public static function of(StockChangedMeanwhile $e): HttpException
    {
        return new HttpException(409, $e->errorCode, $e->text, ['changed' => $e->cells], $e);
    }
}
