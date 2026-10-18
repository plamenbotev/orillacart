<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application\Dto;

enum SearchSort: string
{
    /** The shop's own order (the products' Order field), then by name. */
    case Order = 'order';
    /** How well the product matches the search words; the shop's order without them. */
    case Relevance = 'relevance';
    case Newest = 'newest';
    case Name = 'name';
    /** By today's lowest price; products whose price is asked for come last. */
    case PriceAscending = 'price_asc';
    case PriceDescending = 'price_desc';
}
