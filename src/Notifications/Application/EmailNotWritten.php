<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

/**
 * An email's template failed; it fails the same way until the template is fixed.
 */
final class EmailNotWritten extends \RuntimeException {}
