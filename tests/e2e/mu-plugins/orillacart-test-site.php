<?php

/**
 * Plugin Name: OrillaCart test site
 * Description: The wp-env test site only. Keeps the mail WordPress sends in the option orillacart_test_mail, the
 * last twenty, for the end-to-end tests to read, and sends none (the site makes no outbound calls at all: its
 * .wp-env.json blocks every host but its own and the tests' OpenSearch); and raises the rate limits, which the
 * tests' many orders from one address would reach, and lifts how many orders one shopper has waiting for a
 * transfer, for the same reason; and lets mail go to the tests' example.com addresses; and checks addresses as an
 * add-on would.
 */

declare(strict_types=1);

add_filter('pre_wp_mail', static function (mixed $short, array $mail): mixed {
    $kept = get_option('orillacart_test_mail', []);
    $kept = is_array($kept) ? $kept : [];
    $text = static fn(mixed $value): string => is_string($value) ? $value : '';
    $to = $mail['to'] ?? '';
    $kept[] = [
        'to' => is_array($to) ? implode(', ', array_map($text, $to)) : $text($to),
        'subject' => $text($mail['subject'] ?? ''),
        'message' => $text($mail['message'] ?? ''),
    ];
    update_option('orillacart_test_mail', array_slice($kept, -20), false);

    // Kept, not sent.
    return true;
}, 5, 2);

// The tests' example.com addresses get their mail (kept above), whichever sends it, a request or the worker on
// the command line: the shop holds mail to reserved names back.
add_filter('orillacart_undeliverable_address', '__return_false');

// For the browser's requests only: the integration tests run on the command line, where the shop's limits count
// and the settings read as stored.
if (PHP_SAPI !== 'cli') {
    // A filter cannot switch a limit off, only raise it: each to more than a run of the tests makes.
    add_filter('orillacart_rate_limits', static fn(mixed $limits): array => array_map(
        static fn(mixed $limit): array => [1000000, is_array($limit) && is_int($limit[1] ?? null) ? $limit[1] : 600],
        is_array($limits) ? $limits : [],
    ));

    // The setting "unpaid orders each", off for visitors: every guest of the tests orders from the one address it
    // counts by. The admin reads and saves the setting as stored, and a test's browser with this cookie orders
    // under it.
    add_filter('option_orillacart_settings', static fn(mixed $stored): mixed => is_array($stored) && did_action('init') > 0 && !is_user_logged_in() && ($_COOKIE['orillacart_e2e_unpaid_limit'] ?? '') !== '1'
        ? ['unpaid_orders_each' => null] + $stored
        : $stored);
}

// An address register as an add-on brings one: it knows one street of the tests', and corrects it.
add_action('orillacart_extensions', static function (OrillaCart\Extension\Registry $registry): void {
    $registry->addressCheck(new class implements OrillaCart\Extension\Checkout\AddressCheck {
        public function id(): string
        {
            return 'e2e_register';
        }

        public function check(OrillaCart\Extension\Checkout\CheckedAddress $address): ?OrillaCart\Extension\Checkout\AddressSuggestion
        {
            return $address->street === '1 Vitoshaa'
                ? OrillaCart\Extension\Checkout\AddressSuggestion::corrected('1 Vitosha Blvd', message: 'Did you mean this street?')
                : null;
        }
    });
});

// An add-on's tools for the AI assistant: one that reads, one that changes.
add_action('orillacart_extensions', static function (OrillaCart\Extension\Registry $registry): void {
    if ($registry->version() < 15) {
        return;
    }
    $tool = static fn(string $name, bool $readOnly, ?string $risk, Closure $run): OrillaCart\Extension\Assistant\AssistantTool => new class ($name, $readOnly, $risk, $run) implements OrillaCart\Extension\Assistant\AssistantTool {
        public function __construct(private string $toolName, private bool $reads, private ?string $toolRisk, private Closure $runs) {}

        public function name(): string
        {
            return $this->toolName;
        }

        public function addOn(): string
        {
            return 'E2E';
        }

        public function title(): string
        {
            return ucfirst(str_replace('_', ' ', $this->toolName));
        }

        public function description(): string
        {
            return 'A test add-on\'s tool.';
        }

        public function inputSchema(): array
        {
            return ['type' => 'object', 'properties' => ['word' => ['type' => 'string']], 'additionalProperties' => false];
        }

        public function capability(): string
        {
            return 'manage_orillacart';
        }

        public function readOnly(): bool
        {
            return $this->reads;
        }

        public function risk(): ?string
        {
            return $this->toolRisk;
        }

        public function run(array $arguments, OrillaCart\Extension\Assistant\ToolCall $call): ?array
        {
            $answer = ($this->runs)($arguments, $call);

            return is_array($answer) ? $answer : null;
        }
    };
    $registry->assistantTool($tool('e2e_echo', true, null, static fn(array $arguments, OrillaCart\Extension\Assistant\ToolCall $call): array => ['word' => $arguments['word'] ?? null, 'user' => $call->userId]));
    $registry->assistantTool($tool('e2e_stamp', false, null, static function (array $arguments): array {
        if (($arguments['word'] ?? '') === '') {
            throw new OrillaCart\Extension\Assistant\ToolRefused('Give a word.', ['field' => 'word']);
        }
        update_option('orillacart_e2e_stamp', $arguments['word']);

        return ['stamped' => $arguments['word']];
    }));
});

// An add-on's product block: how much the sale saves.
add_action('orillacart_extensions', static function (OrillaCart\Extension\Registry $registry): void {
    if ($registry->version() < 16) {
        return;
    }
    $registry->productBlock(new class implements OrillaCart\Extension\Storefront\ProductBlock {
        public function id(): string
        {
            return 'e2e_savings';
        }

        public function title(): string
        {
            return 'Savings';
        }

        public function description(): string
        {
            return 'How much the sale saves.';
        }

        public function render(OrillaCart\Extension\Storefront\ShownProduct $product, array $settings): string
        {
            if ($product->regularPrice === null) {
                return '';
            }

            return sprintf('<p class="e2e-savings">%s saves %s</p>', esc_html($product->name), esc_html(number_format((float) $product->regularPrice - (float) $product->price, 2)));
        }
    });
});

// The classic edit screen, as the Classic Editor plugin gives it, while a test's browser has this cookie.
add_filter('use_block_editor_for_post_type', static fn(bool $use, string $type): bool => ($_COOKIE['orillacart_e2e_classic'] ?? '') === '1' ? false : $use, 100, 2);

// A shopper's agent's UCP profile (design 75), at an address the site cannot reach: the tests' agent names it.
add_filter('pre_http_request', static function (mixed $pre, array $args, string $url): mixed {
    if ($url !== 'https://agent.e2e.test/profile.json') {
        return $pre;
    }
    $capabilities = [];
    foreach (['dev.ucp.shopping.catalog.search', 'dev.ucp.shopping.catalog.lookup', 'dev.ucp.shopping.checkout', 'dev.ucp.shopping.fulfillment'] as $name) {
        $capabilities[$name] = [['version' => '2026-08-25']];
    }

    return ['headers' => [], 'body' => (string) wp_json_encode(['ucp' => ['version' => '2026-08-25', 'capabilities' => $capabilities]]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);
