<?php

/**
 * Plugin Name: OrillaCart test site
 * Description: The wp-env test site only. Keeps the mail WordPress sends in the option orillacart_test_mail, the
 * last twenty, for the end-to-end tests to read, and sends none (the site makes no outbound calls at all: its
 * .wp-env.json blocks every host but its own and the tests' OpenSearch); and counts no rate limits, which the
 * tests' many orders from one address would reach; and checks addresses as an add-on would.
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

add_filter('orillacart_rate_limits', static fn(): array => []);

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
