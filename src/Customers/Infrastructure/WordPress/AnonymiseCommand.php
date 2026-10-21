<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Infrastructure\WordPress;

use OrillaCart\Customers\Application\PersonalData;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Shared\WordPress\CliCommand;

/**
 * Erases a person from the shop as a privacy request asks, or the customer from one order.
 */
final readonly class AnonymiseCommand implements CliCommand
{
    public function __construct(
        private PersonalData $personalData,
        private OrderService $orders,
    ) {}

    public static function name(): string
    {
        return 'orillacart anonymise';
    }

    /**
     * Erases a person by their email, as Tools → Erase Personal Data does for the shop: their account's
     * addresses, wishlist, notes and newsletter sign-up go, and their orders keep only what the books need. Or,
     * with --order, the customer's details from one order.
     *
     * ## OPTIONS
     *
     * [<email>]
     * : The person's email.
     *
     * [--order=<number>]
     * : One order instead.
     *
     * [--yes]
     * : Do not ask first.
     *
     * ## EXAMPLES
     *
     *     wp orillacart anonymise ada@example.com
     *     wp orillacart anonymise --order=1042 --yes
     *
     * @param list<string>          $args
     * @param array<string, string> $options
     */
    public function __invoke(array $args, array $options): void
    {
        $order = $options['order'] ?? null;
        $email = $args[0] ?? null;
        if (($order === null) === ($email === null)) {
            \WP_CLI::error('Give an email, or --order=<number>.');
        }
        if ($order !== null) {
            if (!ctype_digit($order)) {
                \WP_CLI::error('An order number is digits.');
            }
            \WP_CLI::confirm("Erase the customer's details from order #{$order}? This cannot be undone.", $options);
            try {
                $this->orders->anonymise((int) $order, null);
            } catch (OrderNotFound) {
                \WP_CLI::error("There is no order #{$order}.");
            }
            \WP_CLI::success("Order #{$order} keeps no customer details now.");

            return;
        }
        \assert(\is_string($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            \WP_CLI::error("{$email} is not an email.");
        }
        \WP_CLI::confirm("Erase {$email} from the shop? This cannot be undone.", $options);
        $removed = false;
        $messages = [];
        $page = 1;
        do {
            $result = $this->personalData->erase($email, $page++);
            $removed = $removed || $result['removed'];
            array_push($messages, ...$result['messages']);
        } while (!$result['done']);
        foreach ($messages as $message) {
            \WP_CLI::log($message);
        }
        \WP_CLI::success($removed ? "Erased {$email}." : "The shop had nothing of {$email}.");
    }
}
