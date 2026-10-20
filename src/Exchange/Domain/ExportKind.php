<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Domain;

/**
 * What the shop exports: its orders, or its products (the file the import reads back).
 */
enum ExportKind: string
{
    case Orders = 'orders';
    case Products = 'products';
}
