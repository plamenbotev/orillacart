<?php

/**
 * Plugin Name: OrillaCart test site
 * Description: The wp-env test site only. Keeps the mail WordPress sends (it has no mail server) in the option
 * orillacart_test_mail, the last twenty, for the end-to-end tests to read; and counts no rate limits, which the
 * tests' many orders from one address would reach.
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

    return $short;
}, 5, 2);

add_filter('orillacart_rate_limits', static fn(): array => []);
