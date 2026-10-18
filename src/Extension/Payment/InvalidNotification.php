<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

/**
 * A notification the provider did not send: its signature does not check out.
 */
final class InvalidNotification extends \RuntimeException {}
