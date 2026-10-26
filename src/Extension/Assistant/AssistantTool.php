<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Assistant;

/**
 * A tool an add-on gives the shop's AI assistant (MCP), beside the shop's own: booking a courier's pickup,
 * reading a marketplace's orders. The shop lists it under the add-on's name; the owner ticks it per API key,
 * and the shop checks the capability and asks the owner first when its risk is one they want to be asked about.
 * Since version 15.
 *
 *     $registry->assistantTool(new class implements AssistantTool { … });
 */
interface AssistantTool
{
    /** Money moves: a refund, a payment, a balance. */
    public const string MONEY = 'money';

    /** Something is deleted for good. */
    public const string DELETING = 'deleting';

    /** Customers hear of it: an email, a message, a decision about their order. */
    public const string CUSTOMERS = 'customers';

    /** The shop opens or closes to buyers, or many settings change at once. */
    public const string OPENING = 'opening';

    /** What customers read or agree to changes: a page, a text of the shop's, goes live or comes down. */
    public const string PUBLISHING = 'publishing';

    /**
     * Lowercase letters, digits and underscores, up to 64, starting with the add-on's own prefix
     * ("parcelco_book_pickup"); a name the shop's tools have is left out.
     */
    public function name(): string;

    /** The add-on's name, which the owner chooses its tools under: "Parcel Co". */
    public function addOn(): string;

    /** A short title: "Book a pickup". */
    public function title(): string;

    /** What it does and what it changes, for the model: the first thing it reads. */
    public function description(): string;

    /**
     * The arguments, as a JSON Schema 2020-12 object schema:
     * ['type' => 'object', 'properties' => [...], 'required' => [...], 'additionalProperties' => false].
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /** The WordPress capability the shop manager needs: 'manage_orillacart' for most. */
    public function capability(): string;

    /** True when it only reads: a read-only key is offered it. */
    public function readOnly(): bool;

    /** One of MONEY, DELETING, CUSTOMERS, OPENING, or null. */
    public function risk(): ?string;

    /**
     * Does it. The arguments are what the model sent; check them as the schema says, and throw ToolRefused with
     * what to correct.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<array-key, mixed>|null what the model reads back, as JSON
     *
     * @throws ToolRefused with a message for the model
     */
    public function run(array $arguments, ToolCall $call): ?array;
}
