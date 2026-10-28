<?php

declare(strict_types=1);

// Polylang's documented functions the shop calls (https://polylang.pro/doc/function-reference/).

/**
 * @param array{hide_empty?: bool, fields?: string} $args
 *
 * @return list<mixed>
 */
function pll_languages_list(array $args = []): array {}

function pll_default_language(string $field = 'slug'): string|false {}

function pll_current_language(string $field = 'slug'): string|false {}

function pll_get_post_language(int $postId, string $field = 'slug'): string|false {}

/**
 * @return array<string, int>
 */
function pll_get_post_translations(int $postId): array {}

/**
 * @return array<string, int>
 */
function pll_get_term_translations(int $termId): array {}
