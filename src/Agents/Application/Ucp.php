<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Application;

/** The Universal Commerce Protocol as the shop speaks it (design 75): its release and capabilities. */
final class Ucp
{
    /** The release the shop implements. */
    public const string VERSION = '2026-08-25';
    public const string SERVICE = 'dev.ucp.shopping';
    public const string SEARCH = 'dev.ucp.shopping.catalog.search';
    public const string LOOKUP = 'dev.ucp.shopping.catalog.lookup';
    public const string CHECKOUT = 'dev.ucp.shopping.checkout';
    public const string FULFILLMENT = 'dev.ucp.shopping.fulfillment';
    /** What the shop offers, and the specification and schema of each. */
    public const array CAPABILITIES = [
        self::SEARCH => ['spec' => 'specification/shopping/catalog/search/', 'schema' => 'schemas/shopping/catalog.json'],
        self::LOOKUP => ['spec' => 'specification/shopping/catalog/lookup/', 'schema' => 'schemas/shopping/catalog.json'],
        self::CHECKOUT => ['spec' => 'specification/shopping/checkout/', 'schema' => 'schemas/shopping/checkout.json'],
        self::FULFILLMENT => ['spec' => 'specification/shopping/fulfillment/', 'schema' => 'schemas/shopping/fulfillment.json', 'extends' => self::CHECKOUT],
    ];
    /** Which capabilities each operation answers with. */
    public const array OPERATIONS = [
        'search_catalog' => [self::SEARCH],
        'lookup_catalog' => [self::LOOKUP],
        'create_checkout' => [self::CHECKOUT, self::FULFILLMENT],
        'get_checkout' => [self::CHECKOUT, self::FULFILLMENT],
        'update_checkout' => [self::CHECKOUT, self::FULFILLMENT],
        'complete_checkout' => [self::CHECKOUT, self::FULFILLMENT],
        'cancel_checkout' => [self::CHECKOUT, self::FULFILLMENT],
    ];
    /** The operations that change a session: an idempotency key is required, and callers are counted. */
    public const array CHANGES = ['create_checkout', 'update_checkout', 'complete_checkout', 'cancel_checkout'];

    public static function url(string $path): string
    {
        return 'https://ucp.dev/' . self::VERSION . '/' . $path;
    }
}
