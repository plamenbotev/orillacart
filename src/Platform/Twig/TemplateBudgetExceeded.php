<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use Twig\Error\Error;

/**
 * An extension's template used up its render's budget. A Twig error but not a RuntimeError, so the renderer does
 * not render it again leniently: it fails as it is.
 */
final class TemplateBudgetExceeded extends Error {}
