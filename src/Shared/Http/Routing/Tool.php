<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Routing;

/**
 * How a shop manager's route appears to the AI assistant (MCP). Every such route is a tool named after its
 * action (ChangeOrderStatusAction: change_order_status) and described by the action's docblock, unless this
 * says otherwise.
 *
 *     #[Tool(risk: Tool::MONEY)]
 *     #[Tool(leftOut: 'Takes an uploaded file; the assistant sends images with upload_image.')]
 *
 * An action with several routes says which one with $path, or $method for one path's methods:
 *
 *     #[Tool(path: '/customers/{id}/addresses', name: 'add_customer_address')]
 *     #[Tool(method: 'PUT', name: 'finish_setup')]
 *
 * A DELETE route is Tool::DELETING without saying so. The owner chooses, per key, which risks need their yes.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class Tool
{
    /** Money moves: a refund, an order marked paid, a gift card's balance, store credit. */
    public const string MONEY = 'money';

    /** Something is deleted. */
    public const string DELETING = 'deleting';

    /** Customers hear of it: an email goes to them, their return is decided. */
    public const string CUSTOMERS = 'customers';

    /** The shop opens or closes to buyers, or the setup changes it in one go. */
    public const string OPENING = 'opening';

    /** @var list<string> */
    public const array RISKS = [self::MONEY, self::DELETING, self::CUSTOMERS, self::OPENING];

    /**
     * @param string|null $name        the tool's name, when the action's would read badly or collide
     * @param string|null $description what the tool does, for a model, when the docblock is written for developers
     * @param string|null $risk        one of RISKS
     * @param string|null $leftOut     why the route is not a tool: it is then left out
     * @param string|null $path        the route this is about, when the action has several
     * @param string|null $method      the method this is about, when the route has several
     * @param array<string, string>|null $wholeBody a body the action reads whole, without a DTO, as one
     *                                              argument: [name => what it is]
     */
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        public ?string $risk = null,
        public ?string $leftOut = null,
        public ?string $path = null,
        public ?string $method = null,
        public ?array $wholeBody = null,
    ) {
        if ($risk !== null && !\in_array($risk, self::RISKS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown risk "%s"; use one of %s.', $risk, implode(', ', self::RISKS)));
        }
        if ($name !== null && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Tool name "%s" must be lower-case letters, digits and underscores.', $name));
        }
    }
}
