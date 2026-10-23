<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Invoice;

/**
 * Sends the shop's e-invoices on, such as through a Peppol access point or a country's own platform (Poland's
 * KSeF, Italy's SdI): each invoice and credit note, as EN 16931 XML in the format the shop chose, once it is
 * issued. It runs in the background and is tried again when it throws.
 */
interface EInvoiceSender
{
    /** Lowercase letters, digits and underscores, up to 40; unique among e-invoice senders. */
    public function id(): string;

    /** As the shop sees it: "Peppol via Storecove". */
    public function label(): string;

    /**
     * @throws \OrillaCart\Extension\ProviderUnavailable to be tried again later
     */
    public function send(EInvoiceFile $file): void;
}
