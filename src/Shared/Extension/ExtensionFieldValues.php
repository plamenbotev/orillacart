<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Extension;

/**
 * The fields extensions declare on the shop's products, orders and customers, as one record holds them: what the
 * shop's own screens show beside the record. Packages, above every module that shows them, answers.
 */
interface ExtensionFieldValues
{
    /**
     * Every declared field of one record, by extension, its default where nothing was set; empty when no extension
     * declares fields on the kind.
     *
     * @param string $owner product, order or customer
     *
     * @return array<string, array<string, mixed>>
     */
    public function of(string $owner, int $id): array;
}
