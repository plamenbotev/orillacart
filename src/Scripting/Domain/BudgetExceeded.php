<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain;

/** The run used up its steps, time, memory or calls. Not catchable by the script: it is stopped. */
final class BudgetExceeded extends \RuntimeException {}
