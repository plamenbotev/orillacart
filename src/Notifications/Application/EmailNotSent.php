<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

/**
 * The mail system refused an email; it may take it later.
 */
final class EmailNotSent extends \RuntimeException {}
